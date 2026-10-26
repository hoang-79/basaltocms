<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Move;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Cloud\CloudCli;
use Basalto\Core\Jobs\AttachSiteDomain;
use Basalto\Core\Jobs\ReceiveInstanceMove;
use Basalto\Core\Jobs\StartInstanceMove;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\InstanceMove;
use Basalto\Core\Models\Site;
use Basalto\Core\Models\SiteDomain;
use Basalto\Core\Saas\Offboarding\AccountArchive;
use Basalto\Core\Saas\Offboarding\AccountEraser;
use Basalto\Core\Saas\Offboarding\Cancellation;
use Basalto\Core\Saas\Offboarding\ExportStorage;
use Basalto\Core\Saas\Offboarding\Lifecycle;
use Basalto\Core\Saas\Offboarding\Notifications\AccountMovedNotification;
use Basalto\Core\Saas\Plan;
use Basalto\Core\Saas\Saas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Umzug eines Kontos zwischen den Instanzen «start» und «pro» (TB 12 Scheibe 4, ADR-008 Konsequenz 4: «über
 * Export/Import mit Alias-Umleitung»). Anstoss: Scheibe 2 setzt beim Planwechsel `accounts.instance_target` und ruft
 * {@see request()} auf — oder der stündliche Lauf findet das gesetzte Ziel ({@see dispatchPending()}).
 *
 * Ablauf (alle Aufrufe signiert, {@see InstanceLink}):
 * 1. Quelle: Konto «umziehend» (Backend nur lesbar), Konto-Archiv in den privaten Speicher, `POST umzug` ans Ziel.
 * 2. Ziel: Auftrag annehmen (202), im Job das Archiv von der Quelle holen (`GET umzug/<uuid>/archiv`, SHA-256 geprüft),
 *    Konto, Benutzer, Sites und Domains anlegen, Domains in der Cloud anhängen, `POST umzug/<uuid>/fertig` an die Quelle.
 * 3. Quelle: Konto «umgezogen» (`instance` = Ziel), Cloud-Domains der Quelle lösen, Stripe-Kennung lösen (Webhooks
 *    gehören jetzt dem Ziel); das Frontend liefert die eingefrorene Kopie weiter, bis die Cloud umgeschaltet hat; das
 *    Backend leitet auf die Zielinstanz um.
 * 4. Ziel: Der Besitzer prüft und bestätigt («Umzug abschliessen») → `POST umzug/<uuid>/bereinigen` → die Quelle löscht
 *    Sites, Medien und Mitgliedschaften; das Konto bleibt dort «umgezogen» stehen (Rechnungen, Aufbewahrung).
 *
 * Alias zwischen den Instanzen: Die Wildcard `*.<alias-domain>` zeigt auf die Instanz `basalto.saas.alias_instance`
 * («start»). Auf jeder anderen Instanz bekommt der Alias einer umgezogenen Site einen eigenen Cloud-Eintrag (ein genauer
 * Host geht vor der Wildcard) — Adresse und DNS bleiben gleich; zieht die Site zurück, löst die Quelle ihren Eintrag
 * wieder, und die Wildcard greift.
 */
final class InstanceMover
{
    public function __construct(
        private readonly AccountArchive $archive,
        private readonly AccountEraser $eraser,
        private readonly InstanceLink $link,
        private readonly CloudCli $cloud,
        private readonly Recorder $recorder,
    ) {}

    /** Instanz, auf die die Wildcard der Alias-Domain zeigt — Sites dort brauchen für den Alias keinen Cloud-Eintrag. */
    public static function aliasInstance(): string
    {
        return (string) config('basalto.saas.alias_instance', 'start');
    }

    /** Zielinstanz eines Plans — null, wenn der Plan auf dieser Instanz läuft. */
    public static function targetFor(string $plan): ?string
    {
        $instance = Plan::find($plan)?->instance;

        return $instance !== null && $instance !== Saas::instance() ? $instance : null;
    }

    // ── Quelle ──────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Schnittstelle für Scheibe 2: das Konto auf die Instanz `$target` umziehen (z. B. Start → Standard: «start» →
     * «pro»). Setzt `instance_target` und stösst den Job an; ist schon ein Umzug offen oder das Ziel diese Instanz,
     * passiert nichts.
     */
    public function request(Account $account, string $target): ?InstanceMove
    {
        if ($target === Saas::instance() || ! in_array($target, InstanceLink::instances(), true) || ! in_array(Lifecycle::state($account), [Lifecycle::ACTIVE, Lifecycle::MOVING], true)) {
            return null;
        }
        $account->forceFill(['instance_target' => $target])->save();
        if (self::openMove($account) !== null) {
            return null;
        }
        $move = InstanceMove::query()->create(['uuid' => (string) Str::uuid(), 'direction' => InstanceMove::OUT, 'account_id' => $account->id,
            'source' => Saas::instance(), 'target' => $target, 'status' => InstanceMove::EXPORTING]);
        $account->forceFill(['status' => Lifecycle::MOVING])->save();
        // Erst nach dem Commit — Scheibe 2 meldet den Planwechsel aus einer Transaktion
        StartInstanceMove::dispatch($move->id)->afterCommit();

        return $move;
    }

    /** Konten mit gesetztem Ziel ohne offenen Umzug (Stand nach Scheibe 2) — für den stündlichen Lauf. */
    public function dispatchPending(): int
    {
        if (! Saas::enabled() || ! InstanceLink::configured()) {
            return 0;
        }
        $count = 0;
        $accounts = Account::query()->whereNotNull('instance_target')->where('instance_target', '!=', Saas::instance())
            ->whereIn('status', [Lifecycle::ACTIVE])->whereNull('cancelled_at')->orderBy('id');
        foreach ($accounts->lazyById(50) as $account) {
            $count += $this->request($account, (string) $account->instance_target) !== null ? 1 : 0;
        }

        return $count;
    }

    public static function openMove(Account $account): ?InstanceMove
    {
        return InstanceMove::query()->where('account_id', $account->id)->where('direction', InstanceMove::OUT)
            ->whereIn('status', [InstanceMove::EXPORTING, InstanceMove::SENT])->latest('id')->first();
    }

    /** Job auf der Quelle: Archiv bauen, ablegen, dem Ziel melden. */
    public function start(InstanceMove $move): void
    {
        $account = $move->account;
        if ($account === null || $move->status !== InstanceMove::EXPORTING) {
            return;
        }
        $file = sys_get_temp_dir().'/basalto-move-'.$move->uuid.'.zip';
        try {
            $result = $this->archive->write($account, $file);
            $disk = ExportStorage::diskName();
            $path = 'moves/'.$move->uuid.'.zip';
            $stream = fopen($result['path'], 'rb');
            if ($stream === false) {
                throw new RuntimeException('Archiv des Umzugs lässt sich nicht lesen.');
            }
            Storage::disk($disk)->writeStream($path, $stream);
            fclose($stream);
            $move->forceFill(['disk' => $disk, 'path' => $path, 'sha256' => hash_file('sha256', $result['path']), 'size' => $result['size']])->save();
            $response = $this->link->post($move->target, 'umzug', ['uuid' => $move->uuid, 'sha256' => $move->sha256, 'size' => $move->size, 'account' => $account->name, 'sites' => $result['sites']]);
            if (! $response->successful()) {
                throw new RuntimeException(sprintf('Zielinstanz «%s» lehnte ab (HTTP %d): %s', $move->target, $response->status(), Str::limit($response->body(), 300)));
            }
            $move->forceFill(['status' => InstanceMove::SENT, 'error' => null])->save();
            $this->recorder->record('account.move_started', $account, null, ['target' => $move->target, 'uuid' => $move->uuid, 'size' => $move->size]);
        } catch (Throwable $e) {
            $this->failOut($move, $e->getMessage());

            throw $e;
        } finally {
            @unlink($file);
        }
    }

    /**
     * Rückmeldung des Ziels: angekommen (oder gescheitert).
     *
     * @param  array<string, mixed>  $result
     */
    public function complete(InstanceMove $move, array $result): void
    {
        $account = $move->account;
        if ($account === null || $move->direction !== InstanceMove::OUT || ! in_array($move->status, [InstanceMove::SENT, InstanceMove::EXPORTING], true)) {
            return;
        }
        if (($result['status'] ?? null) !== 'imported') {
            $this->failOut($move, (string) ($result['error'] ?? 'Import auf der Zielinstanz gescheitert'));

            return;
        }
        DB::transaction(static function () use ($move, $account, $result): void {
            $move->forceFill(['status' => InstanceMove::DONE, 'result' => $result, 'completed_at' => now(), 'error' => null])->save();
            $columns = ['status' => Lifecycle::MOVED, 'instance' => $move->target, 'instance_target' => null];
            // Webhooks von Stripe gehören jetzt dem Ziel: Die Quelle erkennt den Kunden nicht mehr (Rechnungen bleiben)
            if (Schema::hasColumn('accounts', 'stripe_id')) {
                $columns['stripe_id'] = null;
            }
            $account->forceFill($columns)->save();
        });
        foreach ($account->sites()->get() as $site) {
            $this->detachDomains($site);
        }
        $this->deleteArchive($move);
        $this->recorder->record('account.moved', $account, null, ['target' => $move->target, 'uuid' => $move->uuid]);
    }

    /**
     * Bestätigung vom Ziel: Sites, Medien und Mitgliedschaften der Quelle löschen. Das Konto bleibt «umgezogen».
     *
     * @return array{sites: int, files: int, users: int}
     */
    public function cleanup(InstanceMove $move): array
    {
        $account = $move->account;
        if ($account === null || $move->direction !== InstanceMove::OUT || $move->status !== InstanceMove::DONE) {
            return ['sites' => 0, 'files' => 0, 'users' => 0];
        }
        $counts = $this->eraser->eraseSites($account);
        $move->forceFill(['status' => InstanceMove::CLEANED, 'confirmed_at' => now()])->save();
        AccountEraser::log('account.move_cleaned', $account, ['uuid' => $move->uuid] + $counts);

        return $counts;
    }

    private function failOut(InstanceMove $move, string $error): void
    {
        $move->forceFill(['status' => InstanceMove::FAILED, 'error' => Str::limit($error, 2000)])->save();
        $this->deleteArchive($move);
        $account = $move->account;
        if ($account !== null && $account->status === Lifecycle::MOVING) {
            // Zurück auf aktiv und Ziel löschen — sonst versuchte der stündliche Lauf denselben Fehler immer wieder; der
            // Betrieb sieht ihn (Log, `instance_moves`) und stösst nach der Behebung mit `basalto:saas-move` neu an
            $account->forceFill(['status' => Lifecycle::ACTIVE, 'instance_target' => null])->save();
        }
        Log::error('Umzug zwischen Instanzen gescheitert', ['uuid' => $move->uuid, 'target' => $move->target, 'error' => $error]);
    }

    private function deleteArchive(InstanceMove $move): void
    {
        if ($move->disk !== null && $move->path !== null) {
            try {
                Storage::disk($move->disk)->delete($move->path);
            } catch (Throwable) {
                // Archiv bleibt liegen — der Löschlauf der Exporte räumt «moves/» nicht; im Betrieb sichtbar
            }
        }
    }

    /** Cloud-Einträge der Quelle lösen (Kundendomains; Alias-Einträge auf einer Instanz ohne Wildcard). */
    private function detachDomains(Site $site): void
    {
        foreach (SiteDomain::query()->where('site_id', $site->id)->whereNotNull('cloud_domain_id')->get() as $domain) {
            try {
                $this->cloud->domainDelete((string) $domain->cloud_domain_id);
                $domain->forceFill(['cloud_domain_id' => null, 'cloud_status' => 'moved'])->save();
            } catch (Throwable $e) {
                $domain->forceFill(['last_error' => Str::limit($e->getMessage(), 500)])->save();
                Log::warning('Cloud-Domain nach dem Umzug nicht gelöst', ['host' => $domain->host, 'error' => $e->getMessage()]);
            }
        }
    }

    // ── Ziel ────────────────────────────────────────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $payload */
    public function receive(string $source, array $payload): InstanceMove
    {
        $move = InstanceMove::query()->firstOrCreate(['uuid' => (string) $payload['uuid'], 'direction' => InstanceMove::IN], [
            'source' => $source, 'target' => Saas::instance(), 'status' => InstanceMove::QUEUED, 'sha256' => (string) $payload['sha256'], 'size' => (int) $payload['size'],
        ]);
        if ($move->wasRecentlyCreated) {
            ReceiveInstanceMove::dispatch($move->id);
        }

        return $move;
    }

    /** Job auf dem Ziel: Archiv holen und prüfen, Konto übernehmen, Domains anhängen, Quelle benachrichtigen. */
    public function import(InstanceMove $move): void
    {
        if ($move->direction !== InstanceMove::IN || $move->status !== InstanceMove::QUEUED) {
            return;
        }
        $file = sys_get_temp_dir().'/basalto-move-in-'.$move->uuid.'.zip';
        try {
            $sha = $this->link->download($move->source, 'umzug/'.$move->uuid.'/archiv', $file);
            if (! hash_equals((string) $move->sha256, $sha)) {
                throw new RuntimeException('Prüfsumme des Archivs stimmt nicht — Übertragung abgebrochen.');
            }
            $result = $this->archive->import($file);
        } catch (Throwable $e) {
            $move->forceFill(['status' => InstanceMove::FAILED, 'error' => Str::limit($e->getMessage(), 2000)])->save();
            $this->notifySource($move, ['status' => 'failed', 'error' => Str::limit($e->getMessage(), 500)]);

            return;
        } finally {
            @unlink($file);
        }
        $account = $result['account'];
        foreach ($result['sites'] as $site) {
            $this->attachDomains($site);
        }
        $summary = ['status' => 'imported', 'account' => $account->id, 'sites' => array_map(static fn (Site $s): array => ['handle' => $s->handle, 'id' => $s->id], $result['sites']),
            'url' => InstanceLink::baseUrl(Saas::instance()), 'notes' => $result['notes']];
        $move->forceFill(['status' => InstanceMove::IMPORTED, 'account_id' => $account->id, 'result' => $summary, 'completed_at' => now(), 'error' => null])->save();
        $this->recorder->record('account.move_received', $account, null, ['source' => $move->source, 'uuid' => $move->uuid, 'sites' => count($result['sites'])], $result['sites'][0] ?? null, null);
        $this->notifySource($move, $summary);
        Cancellation::notify($account, new AccountMovedNotification($account->name, InstanceLink::baseUrl(Saas::instance()).'/'.trim((string) config('basalto.backend.path', 'admin'), '/').'/konto/kuendigung'));
    }

    /** Der Besitzer bestätigt auf dem Ziel: Quelle bereinigen lassen. */
    public function confirm(InstanceMove $move): void
    {
        if ($move->direction !== InstanceMove::IN || $move->status !== InstanceMove::IMPORTED) {
            return;
        }
        $response = $this->link->post($move->source, 'umzug/'.$move->uuid.'/bereinigen', ['uuid' => $move->uuid]);
        if (! $response->successful()) {
            throw new RuntimeException(sprintf('Die Instanz «%s» hat die Bereinigung nicht bestätigt (HTTP %d).', $move->source, $response->status()));
        }
        $move->forceFill(['status' => InstanceMove::CLEANED, 'confirmed_at' => now()])->save();
        if ($move->account !== null) {
            $this->recorder->record('account.move_confirmed', $move->account, null, ['source' => $move->source, 'uuid' => $move->uuid]);
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function notifySource(InstanceMove $move, array $payload): void
    {
        try {
            $response = $this->link->post($move->source, 'umzug/'.$move->uuid.'/fertig', ['uuid' => $move->uuid] + $payload);
            if (! $response->successful()) {
                Log::error('Rückmeldung an die Quellinstanz abgelehnt', ['uuid' => $move->uuid, 'status' => $response->status()]);
            }
        } catch (Throwable $e) {
            Log::error('Rückmeldung an die Quellinstanz gescheitert', ['uuid' => $move->uuid, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Domains der übernommenen Site in der Cloud dieser Instanz anhängen: Kundendomains immer (die Zeile ohne Paar trägt
     * den Eintrag), Alias und alte Aliasse nur, wenn die Wildcard nicht auf diese Instanz zeigt. Mit kurzer Verzögerung —
     * die Quelle löst ihre Einträge erst nach unserer Rückmeldung.
     */
    private function attachDomains(Site $site): void
    {
        $needsAlias = Saas::instance() !== self::aliasInstance();
        foreach (SiteDomain::query()->where('site_id', $site->id)->orderBy('id')->get() as $domain) {
            $paired = $domain->getAttribute('pair_id') !== null;
            $attach = $domain->kind === SiteDomain::KIND_CUSTOM ? ! $paired : ($needsAlias && str_ends_with($domain->host, '.'.Saas::aliasDomain()));
            if ($domain->kind !== SiteDomain::KIND_CUSTOM && str_ends_with($domain->host, '.'.Saas::aliasDomain())) {
                // Der Alias gehört uns — über die Wildcard bzw. den eigenen Eintrag gilt er ohne DNS-Prüfung beim Kunden
                $domain->forceFill(['verified_at' => now()])->save();
            }
            if ($attach) {
                $domain->forceFill(['cloud_status' => 'pending'])->save();
                AttachSiteDomain::dispatch($domain->id)->delay(now()->addSeconds((int) config('basalto.saas.move_attach_delay', 120)));
            }
        }
    }
}
