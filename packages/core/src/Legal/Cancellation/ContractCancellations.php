<?php

declare(strict_types=1);

namespace Basalto\Core\Legal\Cancellation;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Extranet\Extranet;
use Basalto\Core\Legal\Cancellation\Notifications\CancellationReceivedNotification;
use Basalto\Core\Legal\Cancellation\Notifications\CancellationReviewNotification;
use Basalto\Core\Models\ContractCancellation;
use Basalto\Core\Models\Site;
use Basalto\Core\Notifications\Notify;
use Basalto\Core\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * «Verträge hier kündigen» (CR-011 TB 59, § 312k BGB): Eingang ohne Anmeldung, so weit wie möglich automatisch. Passt
 * die E-Mail-Adresse zu genau einem laufenden Vertrag der gewählten Art, kündigt Basalto ihn auf das nächste Ende (mit
 * Wunschdatum danach: vorgemerkt, der tägliche Lauf kündigt rechtzeitig). Sonst — kein oder mehrere Verträge, eine
 * ausserordentliche Kündigung, «anderer Vertrag» — prüft das Team im Extranet. Die Bestätigung mit Datum und Uhrzeit des
 * Eingangs geht sofort an die angegebene Adresse; ist gekündigt, liegt ein Link zum Zurücknehmen bei.
 *
 * @phpstan-type Input array{kind: string, reason: ?string, name: string, email: string, contract: string, reference: ?string, wish_date: ?string}
 */
final class ContractCancellations
{
    public const PATH = '/_kuendigen';

    public function __construct(private readonly ContractSources $sources, private readonly Recorder $recorder) {}

    /** Adresse der Seite für die Site — null, wenn sich dort nichts kündigen lässt (Sites der SaaS-Kunden, Download ohne Verkauf). */
    public function url(Site $site, ?string $locale = null): ?string
    {
        if ($this->sources->for($site) === []) {
            return null;
        }

        return self::PATH.($locale !== null && $locale !== $site->default_locale ? '?sprache='.$locale : '');
    }

    /**
     * @param  Input  $input
     */
    public function receive(Site $site, array $input, string $locale): ContractCancellation
    {
        $now = CarbonImmutable::now();
        $wish = $input['wish_date'] !== null ? CarbonImmutable::parse($input['wish_date'])->startOfDay() : null;
        $email = mb_strtolower(trim($input['email']));
        [$status, $subject, $end, $note] = $this->match($site, $input, $email, $wish);
        $cancellation = $this->create($site, $now, [
            'kind' => $input['kind'], 'reason' => $input['reason'], 'name' => trim($input['name']), 'email' => $email, 'contract' => $input['contract'],
            'reference' => $input['reference'] !== null ? trim($input['reference']) : null, 'wish_date' => $wish, 'locale' => $locale, 'status' => $status,
            'subject_type' => $subject?->getMorphClass(), 'subject_id' => $subject?->getKey(), 'ends_at' => $end, 'received_at' => $now, 'note' => $note,
        ]);
        $this->recorder->record('contract.cancellation_received', $cancellation, null, ['number' => $cancellation->number, 'status' => $status, 'contract' => $input['contract']], $site);

        $this->confirm($cancellation, false);
        if ($status === ContractCancellation::REVIEW) {
            $this->askTeam($cancellation);
        } elseif (Extranet::enabled()) {
            Notify::make('extranet.cancellations')->title('Kündigung :number über «Verträge hier kündigen»', ['number' => $cancellation->number])
                ->body(':contract — :name', ['contract' => $this->describe($cancellation, 'de'), 'name' => $cancellation->name])
                ->url(route('extranet.cancellations.index'), 'Kündigungen öffnen')->toExtranet()->send();
        }

        return $cancellation;
    }

    /** Vorgemerkte Kündigungen ausführen, sobald das nächste Ende das Wunschdatum erreicht (täglich, `basalto:kuendigungen`). */
    public function applyScheduled(): int
    {
        $applied = 0;
        $due = ContractCancellation::query()->where('status', ContractCancellation::SCHEDULED)->orderBy('id')->get();
        foreach ($due as $cancellation) {
            $source = $this->sources->get($cancellation->contract);
            $subject = $cancellation->subject;
            if ($source === null || ! $subject instanceof Model || $cancellation->wish_date === null) {
                $cancellation->forceFill(['status' => ContractCancellation::REVIEW, 'note' => 'Vertrag zur vorgemerkten Kündigung nicht mehr gefunden.'])->save();
                $this->askTeam($cancellation);

                continue;
            }
            if ($source->earliestEnd($subject)->lessThan($cancellation->wish_date)) {
                continue;
            }
            $end = $source->cancel($subject);
            $cancellation->forceFill(['status' => ContractCancellation::APPLIED, 'ends_at' => $end])->save();
            $this->recorder->record('contract.cancellation_applied', $cancellation, null, ['number' => $cancellation->number, 'ends_at' => $end->toDateString()], $cancellation->site);
            $this->confirm($cancellation, true);
            $applied++;
        }

        return $applied;
    }

    /** Zurücknehmen über den Link in der Bestätigung (bis zum Ende) — false, wenn es nicht mehr geht. */
    public function revoke(ContractCancellation $cancellation): bool
    {
        if (! $cancellation->revocable()) {
            return false;
        }
        if ($cancellation->status === ContractCancellation::APPLIED) {
            $source = $this->sources->get($cancellation->contract);
            $subject = $cancellation->subject;
            if ($source === null || ! $subject instanceof Model || ! $source->revoke($subject)) {
                return false;
            }
        }
        $cancellation->forceFill(['status' => ContractCancellation::REVOKED, 'handled_at' => now()])->save();
        $this->recorder->record('contract.cancellation_revoked', $cancellation, null, ['number' => $cancellation->number], $cancellation->site);
        $this->confirm($cancellation, true);

        return true;
    }

    /** Das Team hat zugeordnet und gekündigt (Ende) oder keinen Vertrag gefunden — die Person erhält die Bestätigung. */
    public function resolve(ContractCancellation $cancellation, ?CarbonImmutable $end, ?string $note, Authenticatable $by): void
    {
        $cancellation->forceFill([
            'status' => $end !== null ? ContractCancellation::DONE : ContractCancellation::REJECTED, 'ends_at' => $end?->startOfDay(),
            'note' => $note, 'handled_at' => now(), 'handled_by' => (int) $by->getAuthIdentifier(),
        ])->save();
        $this->recorder->record('contract.cancellation_resolved', $cancellation, null, ['number' => $cancellation->number, 'status' => $cancellation->status], $cancellation->site, $by);
        $this->confirm($cancellation, true);
    }

    /** Der Vertrag in einem Satzteil — der zugeordnete, sonst die gewählte Art. */
    public function describe(ContractCancellation $cancellation, string $locale): string
    {
        $source = $this->sources->get($cancellation->contract);
        $subject = $cancellation->subject;
        if ($source !== null && $subject instanceof Model) {
            return $source->describe($subject, $locale);
        }

        return $source !== null ? __($source->label(), [], $locale) : __('Anderer Vertrag', [], $locale);
    }

    /** Was aus der Kündigung wird — ein Satz mit Markdown-Fett, für Mail und Eingangsbestätigung im Browser. */
    public function outcome(ContractCancellation $cancellation, string $locale): string
    {
        return match ($cancellation->status) {
            ContractCancellation::APPLIED, ContractCancellation::DONE => __('**Ihr Vertrag endet am :date.** Bis dahin läuft alles wie bisher.', ['date' => (string) $cancellation->ends_at?->format('d.m.Y')], $locale),
            ContractCancellation::SCHEDULED => __('**Ihr Vertrag endet auf das erste mögliche Ende ab dem :date.** Das genaue Datum bestätigen wir Ihnen, sobald wir die Kündigung ausführen.',
                ['date' => (string) $cancellation->wish_date?->format('d.m.Y')], $locale),
            ContractCancellation::REJECTED => __('**Zu Ihren Angaben haben wir keinen laufenden Vertrag gefunden.** Antworten Sie auf die Bestätigung per E-Mail, wenn wir etwas übersehen haben.', [], $locale),
            ContractCancellation::REVOKED => __('**Die Kündigung ist zurückgenommen.** Ihr Vertrag läuft weiter.', [], $locale),
            default => __('**Wir ordnen Ihre Kündigung dem Vertrag zu** und bestätigen Ihnen das Ende innert zwei Werktagen. Sie wirkt auf den Zeitpunkt, den Sie gewählt haben, frühestens auf das nächstmögliche Ende.', [], $locale),
        };
    }

    /** @return list<string> */
    public function notes(ContractCancellation $cancellation, string $locale): array
    {
        $source = $this->sources->get($cancellation->contract);
        $subject = $cancellation->subject;

        return $source !== null && $subject instanceof Model && $cancellation->status === ContractCancellation::APPLIED ? $source->notes($subject, $locale) : [];
    }

    /** Signierter Link zum Zurücknehmen — gültig bis zum Ende, höchstens ein Jahr. */
    public function revokeUrl(ContractCancellation $cancellation): ?string
    {
        if (! $cancellation->revocable()) {
            return null;
        }
        $until = $cancellation->ends_at ?? CarbonImmutable::now()->addYear();

        return self::onSite($cancellation, URL::temporarySignedRoute('basalto.cancel.revoke', $until->endOfDay(), ['cancellation' => $cancellation->id], false));
    }

    /** Signierte Adresse der Eingangsbestätigung im Browser (30 Tage). */
    public function receiptUrl(ContractCancellation $cancellation): string
    {
        return self::onSite($cancellation, URL::temporarySignedRoute('basalto.cancel.receipt', now()->addDays(30), ['cancellation' => $cancellation->id], false));
    }

    /** Signiert wird relativ — der Link zeigt auf die Site, auf der gekündigt wurde (dort prüft der Controller die Site). */
    private static function onSite(ContractCancellation $cancellation, string $relative): string
    {
        $site = $cancellation->site;

        return $site !== null ? rtrim($site->publicUrl(), '/').$relative : url($relative);
    }

    /**
     * @param  Input  $input
     * @return array{0: string, 1: ?Model, 2: ?CarbonImmutable, 3: ?string}
     */
    private function match(Site $site, array $input, string $email, ?CarbonImmutable $wish): array
    {
        $source = $this->sources->get($input['contract']);
        if ($source === null || ! $source->available($site) || $input['kind'] !== ContractCancellation::ORDINARY) {
            return [ContractCancellation::REVIEW, null, null, null];
        }
        $found = $source->find($site, $email, $input['reference']);
        if (count($found) !== 1) {
            return [ContractCancellation::REVIEW, null, null, count($found) === 0 ? 'Kein laufender Vertrag zur E-Mail-Adresse.' : sprintf('%d Verträge zur E-Mail-Adresse.', count($found))];
        }
        $subject = $found[0];
        try {
            if ($wish !== null && $wish->greaterThan($source->earliestEnd($subject))) {
                return [ContractCancellation::SCHEDULED, $subject, null, null];
            }

            return [ContractCancellation::APPLIED, $subject, $source->cancel($subject), null];
        } catch (Throwable $e) {
            report($e);

            return [ContractCancellation::REVIEW, $subject, null, 'Automatisch kündigen ging nicht: '.mb_substr($e->getMessage(), 0, 200)];
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function create(Site $site, CarbonImmutable $now, array $attributes): ContractCancellation
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return ContractCancellation::query()->create(['site_id' => $site->id, 'number' => ContractCancellation::nextNumber($now)] + $attributes);
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 5) {
                    throw $e;
                }
            }
        }
    }

    /** Bestätigung an die angegebene Adresse — in der Sprache der Seite, im Layout der Site, auf der gekündigt wurde. */
    private function confirm(ContractCancellation $cancellation, bool $update): void
    {
        Notification::route('mail', $cancellation->email)->route('site', $cancellation->site_id)
            ->notify((new CancellationReceivedNotification($cancellation->id, $update))->locale($cancellation->locale));
    }

    private function askTeam(ContractCancellation $cancellation): void
    {
        Notification::route('mail', Vendor::email())->notify(new CancellationReviewNotification($cancellation->id));
        if (Extranet::enabled()) {
            Notify::make('extranet.cancellations')->title('Kündigung :number zuordnen', ['number' => $cancellation->number])
                ->body(':contract — :name, :email', ['contract' => $this->describe($cancellation, 'de'), 'name' => $cancellation->name, 'email' => $cancellation->email])
                ->url(route('extranet.cancellations.index', ['filter' => 'review']), 'Kündigungen öffnen')->toExtranet()->send();
        }
    }
}
