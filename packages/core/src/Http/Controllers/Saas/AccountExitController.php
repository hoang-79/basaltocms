<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers\Saas;

use Basalto\Core\Accounts\AccountRole;
use Basalto\Core\Http\Controllers\SiteExportController;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\AccountExport;
use Basalto\Core\Models\InstanceMove;
use Basalto\Core\Models\Site;
use Basalto\Core\Saas\AccountContext;
use Basalto\Core\Saas\Move\InstanceMover;
use Basalto\Core\Saas\Offboarding\AccountExports;
use Basalto\Core\Saas\Offboarding\Cancellation;
use Basalto\Core\Saas\Offboarding\ExportStorage;
use Basalto\Core\Saas\Offboarding\Lifecycle;
use Basalto\Core\Saas\Plan;
use Basalto\Core\Saas\Trial;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Konto › Export und Kündigung (TB 12 Scheibe 4, M-21, Mockup 24): Export je Site oder ganzes Konto (asynchron, mit
 * Fortschritt, Mail mit Link, 7 Tage), Kündigung mit Laufzeit, Sperre und Löschdatum, «Kündigung zurücknehmen», nach
 * einem Umzug zwischen Instanzen «Umzug abschliessen». Seite `core::Saas/Account/Exit`, Route `basalto.saas.account.exit`.
 *
 * Rechte: Export einer Site wie «Export herunterladen» ({@see SiteExportController::allows()}); Export des ganzen Kontos,
 * Kündigung, Zurücknehmen und Abschluss des Umzugs nur der Besitzer des Kontos.
 */
final class AccountExitController
{
    public function __construct(private readonly AccountExports $exports, private readonly Cancellation $cancellation) {}

    public function __invoke(Request $request): Response
    {
        $account = AccountContext::from($request);
        $user = $request->user();
        $owner = $user !== null && self::owns($user, $account);
        $zone = Lifecycle::timezone($account);
        $dates = Lifecycle::dates($account);
        $state = $dates['state'];
        $preview = $state === Lifecycle::ACTIVE ? $this->cancellation->previewEnd($account) : null;
        $move = InstanceMove::query()->where('account_id', $account->id)->where('direction', InstanceMove::IN)->latest('id')->first();

        return AccountPage::render($request, 'Exit', 'exit', [
            'is_owner' => $owner,
            'sites' => $account->sites()->orderBy('id')->get()->map(static fn (Site $site): array => [
                'id' => $site->id, 'name' => $site->name, 'handle' => $site->handle,
                'can_export' => $user !== null && SiteExportController::allows($user, $site),
            ])->values()->all(),
            'exports' => AccountExport::query()->where('account_id', $account->id)->latest('id')->limit(5)->get()
                ->map(fn (AccountExport $export): array => [
                    'id' => $export->uuid, 'label' => $this->exports->label($export), 'status' => $export->status, 'progress' => $export->progress,
                    'size' => $export->size, 'created' => $export->created_at->timezone($zone)->format('d.m.Y H:i'),
                    'expires' => Lifecycle::date($export->expires_at, $zone), 'download_url' => $this->exports->downloadUrl($export),
                ])->values()->all(),
            'export_days' => ExportStorage::days(),
            'exit' => $dates + [
                'in_trial' => Trial::inTrial($account),
                'plan' => Plan::find($account->plan)?->label(),
                'preview_ends' => $preview === null ? null : Lifecycle::lastDay($preview, $zone),
                'preview_deletes' => $preview === null ? null : Lifecycle::date($preview->addDays(Lifecycle::lockDays()), $zone),
                'immediate' => $preview !== null && $preview->lessThanOrEqualTo(now()->addMinute()),
            ],
            'move' => $move === null ? null : [
                'id' => $move->uuid, 'status' => $move->status, 'source' => $move->source, 'target' => $move->target,
                'completed' => $move->completed_at?->timezone($zone)->format('d.m.Y H:i'), 'confirmed' => $move->confirmed_at?->timezone($zone)->format('d.m.Y H:i'),
                'can_confirm' => $owner && $move->status === InstanceMove::IMPORTED,
            ],
            'urls' => [
                'export' => route('basalto.saas.account.exit.export'),
                'cancel' => route('basalto.saas.account.exit.cancel'),
                'revoke' => route('basalto.saas.account.exit.revoke'),
                'confirm_move' => $move === null ? null : route('basalto.saas.account.exit.move', $move->uuid),
            ],
        ]);
    }

    /** «Neuen Export erstellen» — je Site oder (ohne `site`) das ganze Konto. */
    public function export(Request $request): RedirectResponse
    {
        $account = AccountContext::from($request);
        $user = $request->user();
        abort_if($user === null, 404);
        $data = $request->validate(['site' => ['nullable', 'integer']]);
        $site = isset($data['site']) ? AccountContext::site($account, (int) $data['site']) : null;
        abort_unless($site !== null ? SiteExportController::allows($user, $site) : self::owns($user, $account), 403);
        $this->exports->request($account, $site, $user);

        return back()->with('status', __('Export wird erstellt — wir mailen, wenn er fertig ist.'));
    }

    /**
     * Download über den signierten Link (7 Tage) — nur angemeldet und mit Recht am Konto bzw. an der Site; fremde Exporte
     * sind 404 wie jede fremde Ressource.
     */
    public function download(Request $request, string $export): StreamedResponse|RedirectResponse
    {
        $user = $request->user();
        $record = AccountExport::query()->where('uuid', $export)->first();
        $account = $record?->account;
        abort_if($user === null || $record === null || $account === null, 404);
        $site = $record->site_id !== null ? Site::query()->find($record->site_id) : null;
        $allowed = $site !== null ? SiteExportController::allows($user, $site) : self::owns($user, $account);
        abort_unless($allowed, 404);
        if (! $record->isAvailable() || $record->disk === null || $record->path === null) {
            return redirect()->route('basalto.saas.account.exit')->withErrors(['export' => __('Dieser Export ist abgelaufen — erstellen Sie einen neuen.')]);
        }

        return Storage::disk($record->disk)->download($record->path, (string) $record->filename, ['Content-Type' => 'application/zip']);
    }

    /** Kündigen — Bestätigung mit dem Namen des Kontos und dem Haken zum Export (Mockup 24). */
    public function cancel(Request $request): RedirectResponse
    {
        $account = AccountContext::from($request);
        $user = $request->user();
        abort_unless($user !== null && self::owns($user, $account), 403);
        $data = $request->validate(['confirm' => ['required', 'string', 'max:255'], 'exported' => ['accepted']], [
            'exported.accepted' => __('Bitte bestätigen Sie, dass Sie den Export erstellt haben oder ihn nicht brauchen.'),
        ]);
        if (mb_strtolower(trim((string) $data['confirm'])) !== mb_strtolower(trim($account->name))) {
            throw ValidationException::withMessages(['confirm' => __('Zur Bestätigung den Namen des Kontos genau so eingeben: :name', ['name' => $account->name])]);
        }
        $this->cancellation->cancel($account, $user);
        $dates = Lifecycle::dates($account->refresh());

        return back()->with('status', $dates['state'] === Lifecycle::LOCKED
            ? __('Gekündigt — die Sites sind ab sofort gesperrt. Löschung am :date.', ['date' => $dates['deletes']])
            : __('Gekündigt — Laufzeit bis :ends, Löschung am :deletes.', ['ends' => $dates['ends'], 'deletes' => $dates['deletes']]));
    }

    public function revoke(Request $request): RedirectResponse
    {
        $account = AccountContext::from($request);
        $user = $request->user();
        abort_unless($user !== null && self::owns($user, $account), 403);
        $this->cancellation->revoke($account, $user);

        return back()->with('status', __('Kündigung zurückgenommen — alles läuft weiter wie bisher.'));
    }

    /** Auf dem Ziel eines Umzugs: der Besitzer bestätigt, die Quelle löscht ihre Kopie. */
    public function confirmMove(Request $request, string $move, InstanceMover $mover): RedirectResponse
    {
        $account = AccountContext::from($request);
        $user = $request->user();
        $record = InstanceMove::query()->where('uuid', $move)->where('direction', InstanceMove::IN)->where('account_id', $account->id)->first();
        abort_if($record === null, 404);
        abort_unless($user !== null && self::owns($user, $account), 403);
        try {
            $mover->confirm($record);
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['move' => __('Die alte Instanz hat nicht geantwortet — bitte später nochmals versuchen.')]);
        }

        return back()->with('status', __('Umzug abgeschlossen — die alte Kopie wird gelöscht.'));
    }

    private static function owns(Authenticatable $user, Account $account): bool
    {
        return $account->roleOf($user) === AccountRole::Owner;
    }
}
