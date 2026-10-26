<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Offboarding;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Jobs\BuildAccountExport;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\AccountExport;
use Basalto\Core\Models\Site;
use Basalto\Core\Saas\Offboarding\Notifications\ExportReadyNotification;
use Basalto\Core\Transfer\SiteExporter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Exporte aus dem Konto-Bereich (TB 12 Scheibe 4, M-21, Mockup 24 «Export asynchron mit Fortschritt»): je Site (der
 * Vollexport aus TB 09) oder das ganze Konto ({@see AccountArchive}), gebaut als Job in der Warteschlange, abgelegt im
 * privaten Speicher, per Mail mit signiertem Link gemeldet, nach {@see ExportStorage::days()} Tagen gelöscht.
 * Je Konto läuft höchstens ein Export gleichzeitig.
 */
final class AccountExports
{
    public function __construct(private readonly AccountArchive $archive, private readonly SiteExporter $exporter, private readonly Recorder $recorder) {}

    public function request(Account $account, ?Site $site, Authenticatable $user): AccountExport
    {
        if (AccountExport::query()->where('account_id', $account->id)->whereIn('status', [AccountExport::QUEUED, AccountExport::RUNNING])->exists()) {
            throw ValidationException::withMessages(['export' => __('Ein Export läuft schon — wir mailen, wenn er fertig ist.')]);
        }
        $export = AccountExport::query()->create(['account_id' => $account->id, 'site_id' => $site?->id, 'user_id' => (int) $user->getAuthIdentifier(), 'status' => AccountExport::QUEUED, 'progress' => 0]);
        $this->recorder->record('account.export_requested', $account, null, ['scope' => $site === null ? 'account' : 'site', 'site' => $site?->handle], $site, $user);
        BuildAccountExport::dispatch($export->id);

        return $export;
    }

    /** Job: Archiv bauen, in den privaten Speicher legen, Mail an die Person, die ihn angefordert hat. */
    public function build(AccountExport $export): void
    {
        if (! $export->isOpen() || $export->account === null) {
            return;
        }
        $account = $export->account;
        $export->forceFill(['status' => AccountExport::RUNNING, 'progress' => 5])->save();
        $file = sys_get_temp_dir().'/basalto-account-export-'.$export->uuid.'.zip';
        try {
            $site = $export->site_id !== null ? $account->sites()->whereKey($export->site_id)->first() : null;
            if ($export->site_id !== null && $site === null) {
                throw new RuntimeException(__('Die Site gibt es nicht mehr.'));
            }
            if ($site !== null) {
                $result = $this->exporter->export($site, $file);
                $name = sprintf('basalto-site-%s-%s.zip', $site->handle, now()->format('Ymd-His'));
            } else {
                $result = $this->archive->write($account, $file, static function (int $percent) use ($export): void {
                    $export->forceFill(['progress' => max(5, min(95, $percent))])->save();
                });
                $name = sprintf('basalto-konto-%s-%s.zip', Str::slug($account->name) ?: 'konto', now()->format('Ymd-His'));
            }
            $export->forceFill(['progress' => 95])->save();
            $disk = ExportStorage::diskName();
            $path = 'exports/accounts/'.$account->id.'/'.$export->uuid.'.zip';
            $stream = fopen($file, 'rb');
            if ($stream === false) {
                throw new RuntimeException('Export lässt sich nicht lesen.');
            }
            Storage::disk($disk)->writeStream($path, $stream);
            fclose($stream);
            $export->forceFill(['status' => AccountExport::READY, 'progress' => 100, 'disk' => $disk, 'path' => $path, 'filename' => $name, 'size' => (int) $result['size'],
                'ready_at' => now(), 'expires_at' => now()->addDays(ExportStorage::days()), 'error' => null])->save();
        } catch (Throwable $e) {
            $export->forceFill(['status' => AccountExport::FAILED, 'error' => Str::limit($e->getMessage(), 1000)])->save();

            throw $e;
        } finally {
            @unlink($file);
        }
        $user = $export->user_id === null ? null : $this->user($export->user_id);
        if ($user !== null && method_exists($user, 'notify')) {
            $zone = Lifecycle::timezone($account);
            $user->notify(new ExportReadyNotification($export->site_id !== null ? Site::query()->find($export->site_id)?->name : null, Number::fileSize((int) $export->size, 1), (string) Lifecycle::date($export->expires_at, $zone), (string) $this->downloadUrl($export)));
        }
    }

    /** Signierter Link bis zum Ablauf — zusätzlich braucht der Abruf eine Anmeldung mit Recht am Konto. */
    public function downloadUrl(AccountExport $export): ?string
    {
        if (! $export->isAvailable() || $export->expires_at === null) {
            return null;
        }

        return URL::temporarySignedRoute('basalto.saas.account.exit.download', $export->expires_at, ['export' => $export->uuid]);
    }

    public function label(AccountExport $export): string
    {
        $site = $export->site_id !== null ? Site::query()->find($export->site_id) : null;

        return $site !== null ? __('Site «:name»', ['name' => $site->name]) : __('Ganzes Konto');
    }

    /** Löschlauf: abgelaufene Exporte (Datei und Zeile) und hängengebliebene Aufträge nach einem Tag. */
    public static function purgeExpired(): int
    {
        $count = 0;
        $expired = AccountExport::query()->where(static fn ($q) => $q->where('expires_at', '<', now())
            ->orWhere(static fn ($q) => $q->whereIn('status', [AccountExport::QUEUED, AccountExport::RUNNING, AccountExport::FAILED])->where('created_at', '<', now()->subDay())));
        foreach ($expired->get() as $export) {
            AccountEraser::deleteExportFile($export);
            $export->delete();
            $count++;
        }

        return $count;
    }

    private function user(int $id): ?object
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');

        return $model::query()->find($id);
    }
}
