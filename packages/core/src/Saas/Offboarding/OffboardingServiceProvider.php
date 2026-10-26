<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Offboarding;

use Basalto\Core\Backend\Backend;
use Basalto\Core\Console\SaasExitCommand;
use Basalto\Core\Console\SaasMoveCommand;
use Basalto\Core\Extranet\InstanceSummary;
use Basalto\Core\Http\Controllers\Saas\AccountExitController;
use Basalto\Core\Http\Controllers\Saas\InstanceMoveController;
use Basalto\Core\Http\Middleware\EnsureSaasMode;
use Basalto\Core\Http\Middleware\GuardAccountLifecycle;
use Basalto\Core\Http\Middleware\ResolveSaasAccount;
use Basalto\Core\Http\Middleware\VerifyInstanceSignature;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\Site;
use Basalto\Core\Saas\Events\InstanceMoveRequested;
use Basalto\Core\Saas\Move\InstanceLink;
use Basalto\Core\Saas\Move\InstanceMover;
use Basalto\Core\Saas\Saas;
use Basalto\Core\Sites\CurrentSite;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * TB 12 Scheibe 4 (M-21, ADR-008): Austritt aus der SaaS und Umzug zwischen Instanzen — Export im Konto-Bereich,
 * Kündigung mit Laufzeit und Zurücknehmen, Sperre ({@see LockedSite}, {@see GuardAccountLifecycle}),
 * Löschung im Löschlauf ({@see AccountEraser}), Umzug «start» ↔ «pro» ({@see InstanceMover}).
 *
 * Routen: Konto › Export und Kündigung `basalto.saas.account.exit.*`; Schnittstelle zwischen den Instanzen
 * `/_basalto/instanz/umzug…` (`basalto.saas.instance.*`, signiert). Zeitplan: `basalto:saas-exit` stündlich; der
 * Löschlauf `basalto:cleanup` löscht Konten nach der Sperre und abgelaufene Exporte.
 */
final class OffboardingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CancellationBilling::class, AccountBillingCancellation::class);
    }

    public function boot(): void
    {
        $this->routes($this->app->make(Backend::class));

        // Scheibe 2 meldet den Planwechsel über die Grenze der Instanzen — hier wird umgezogen
        Event::listen(InstanceMoveRequested::class, static function (InstanceMoveRequested $event): void {
            if (Saas::enabled() && InstanceLink::configured()) {
                app(InstanceMover::class)->request($event->account, $event->to);
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([SaasExitCommand::class, SaasMoveCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            if (Saas::enabled()) {
                $schedule->command('basalto:saas-exit')->hourly()->onOneServer();
            }
        });
        // Löschlauf: Konten nach der Sperre löschen (Sites, Medien, Benutzer; Konto anonymisiert, Rechnungen bleiben) und Exporte nach 7 Tagen
        $this->app->instance('basalto.cleanup.saas-exit', static function (): array {
            if (! Saas::enabled()) {
                return [];
            }
            $erased = 0;
            $accounts = Account::query()->whereIn('status', [Lifecycle::CANCELLED, Lifecycle::LOCKED])->whereNull('erased_at')->whereNotNull('delete_after')
                ->where('delete_after', '<=', now())->orderBy('id');
            foreach ($accounts->lazyById(20) as $account) {
                if (Saas::isCustomer($account) && Lifecycle::state($account) === Lifecycle::LOCKED) {
                    app(AccountEraser::class)->erase($account);
                    $erased++;
                }
            }
            if ($erased > 0) {
                // Die gewählte Site kann eben gelöscht worden sein — der Löschlauf protokolliert danach ohne Site
                $current = app(CurrentSite::class);
                if ($current->get() !== null && ! Site::query()->whereKey($current->get()->id)->exists()) {
                    $current->set(null);
                }
            }
            $exports = AccountExports::purgeExpired();

            return array_filter(['Gelöschte Konten' => $erased, 'Exporte' => $exports]);
        });
        $this->app->tag(['basalto.cleanup.saas-exit'], 'basalto.cleanup');
    }

    private function routes(Backend $backend): void
    {
        $backend->routes(static function (): void {
            Route::middleware([EnsureSaasMode::class, ResolveSaasAccount::class])->prefix('konto')->name('saas.account.exit.')->group(static function (): void {
                Route::post('kuendigung/export', [AccountExitController::class, 'export'])->name('export');
                Route::post('kuendigung', [AccountExitController::class, 'cancel'])->name('cancel');
                Route::post('kuendigung/zuruecknehmen', [AccountExitController::class, 'revoke'])->name('revoke');
                Route::post('umzug/{move}/abschliessen', [AccountExitController::class, 'confirmMove'])->whereUuid('move')->name('move');
            });
            // Der Link aus der Mail: signiert (7 Tage) und angemeldet — das Konto kommt aus dem Export, nicht aus der gewählten Site
            Route::middleware([EnsureSaasMode::class, 'signed'])->get('konto/export/{export}', [AccountExitController::class, 'download'])->whereUuid('export')->name('saas.account.exit.download');
        });

        Route::middleware([EnsureSaasMode::class, VerifyInstanceSignature::class, 'throttle:120,1'])->prefix(InstanceLink::PATH)->name('basalto.saas.instance.')->group(static function (): void {
            Route::post('umzug', [InstanceMoveController::class, 'receive'])->name('receive');
            Route::get('umzug/{uuid}/archiv', [InstanceMoveController::class, 'archive'])->whereUuid('uuid')->name('archive');
            Route::post('umzug/{uuid}/fertig', [InstanceMoveController::class, 'complete'])->whereUuid('uuid')->name('complete');
            Route::post('umzug/{uuid}/bereinigen', [InstanceMoveController::class, 'cleanup'])->whereUuid('uuid')->name('cleanup');
            // TB 19 (P-15): Zustand dieser Instanz für den SaaS-Betrieb im Extranet der anderen
            Route::get(InstanceSummary::SUFFIX, static fn () => response()->json(InstanceSummary::local()))->name('summary');
        });
    }
}
