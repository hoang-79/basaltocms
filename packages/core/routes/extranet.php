<?php

declare(strict_types=1);

use Basalto\Core\Extranet\Extranet;
use Basalto\Core\Extranet\ExtranetRegistry;
use Basalto\Core\Http\Controllers\Extranet\CancellationsController;
use Basalto\Core\Http\Controllers\Extranet\CustomerController;
use Basalto\Core\Http\Controllers\Extranet\InvoiceController;
use Basalto\Core\Http\Controllers\Extranet\OverviewController;
use Basalto\Core\Http\Controllers\Extranet\SaasController;
use Basalto\Core\Http\Controllers\Extranet\StatisticsController;
use Basalto\Core\Http\Controllers\Extranet\SupportController;
use Basalto\Core\Http\Controllers\Extranet\TeamController;
use Basalto\Core\Http\Controllers\NotificationController;
use Basalto\Core\Http\Middleware\GuardExtranetAccess;
use Basalto\Core\Http\Middleware\HandleExtranetRequests;
use Basalto\Core\Http\Middleware\ResolveExtranet;
use Basalto\Core\Http\Middleware\SelectAccountLocale;
use Illuminate\Support\Facades\Route;

/*
 * TB 19 (CR-004, P-09–P-16): Hersteller-Extranet auf eigenem Host. Angemeldet wird wie im Backend (`/admin/login` auf
 * demselben Host, danach zurück); jede Route prüft Team und Bereich. Module hängen ihre Routen über das Register ein.
 */
// Sprache wie im Backend: die des Kontos, sonst die des Browsers
Route::domain(Extranet::host())->middleware(['web', 'auth', SelectAccountLocale::class, ResolveExtranet::class, GuardExtranetAccess::class, HandleExtranetRequests::class])->group(static function (): void {
    Route::get('/', OverviewController::class)->name('extranet.home');
    Route::get('kunden', [CustomerController::class, 'index'])->name('extranet.customers.index');
    Route::get('kunden/{source}/{id}', [CustomerController::class, 'show'])->where('source', '[a-z]+')->whereNumber('id')->name('extranet.customers.show');
    Route::post('kunden/notizen', [CustomerController::class, 'note'])->name('extranet.customers.note');
    // CR-011 TB 59: Kündigungen über «Verträge hier kündigen»
    Route::get('kuendigungen', [CancellationsController::class, 'index'])->name('extranet.cancellations.index');
    Route::post('kuendigungen/{cancellation}/erledigen', [CancellationsController::class, 'resolve'])->whereNumber('cancellation')->name('extranet.cancellations.resolve');
    Route::get('rechnungen', [InvoiceController::class, 'index'])->name('extranet.invoices.index');
    Route::get('rechnungen/{source}/{id}/pdf', [InvoiceController::class, 'pdf'])->where('source', '[a-z]+')->whereNumber('id')->name('extranet.invoices.pdf');
    Route::post('rechnungen/{source}/{id}/bezahlt', [InvoiceController::class, 'paid'])->where('source', '[a-z]+')->whereNumber('id')->name('extranet.invoices.paid');
    Route::post('rechnungen/{source}/{id}/erinnern', [InvoiceController::class, 'remind'])->where('source', '[a-z]+')->whereNumber('id')->name('extranet.invoices.remind');
    Route::post('rechnungen/{source}/{id}/senden', [InvoiceController::class, 'send'])->where('source', '[a-z]+')->whereNumber('id')->name('extranet.invoices.send');
    Route::post('rechnungen/{source}/{id}/gutschrift', [InvoiceController::class, 'credit'])->where('source', '[a-z]+')->whereNumber('id')->name('extranet.invoices.credit');
    Route::get('gutschriften/{note}/pdf', [InvoiceController::class, 'creditPdf'])->whereNumber('note')->name('extranet.invoices.credit_pdf');
    Route::get('saas', [SaasController::class, 'index'])->name('extranet.saas.index');
    // TB 55: Statistik von Website, Produkt, Verkauf und Community
    Route::get('statistik', StatisticsController::class)->name('extranet.statistics.index');
    Route::get('support', [SupportController::class, 'index'])->name('extranet.support.index');
    Route::post('support', [SupportController::class, 'start'])->name('extranet.support.start');
    Route::post('support/{session}/beenden', [SupportController::class, 'end'])->whereNumber('session')->name('extranet.support.end');
    // TB 27: Glocke und Center des Teams (Einstellungen im Profil des CMS)
    Route::get('benachrichtigungen', [NotificationController::class, 'index'])->name('extranet.notifications.index');
    Route::get('benachrichtigungen/glocke', [NotificationController::class, 'bell'])->name('extranet.notifications.bell');
    Route::post('benachrichtigungen/alle-gelesen', [NotificationController::class, 'readAll'])->name('extranet.notifications.read_all');
    Route::delete('benachrichtigungen/gelesene', [NotificationController::class, 'destroyRead'])->name('extranet.notifications.destroy_read');
    Route::get('benachrichtigungen/{notification}', [NotificationController::class, 'open'])->whereUuid('notification')->name('extranet.notifications.open');
    Route::post('benachrichtigungen/{notification}/gelesen', [NotificationController::class, 'read'])->whereUuid('notification')->name('extranet.notifications.read');
    Route::delete('benachrichtigungen/{notification}', [NotificationController::class, 'destroy'])->whereUuid('notification')->name('extranet.notifications.destroy');
    Route::get('team', [TeamController::class, 'index'])->name('extranet.team.index');
    Route::post('team', [TeamController::class, 'store'])->name('extranet.team.store');
    Route::put('team/{member}', [TeamController::class, 'update'])->whereNumber('member')->name('extranet.team.update');
    Route::delete('team/{member}', [TeamController::class, 'destroy'])->whereNumber('member')->name('extranet.team.destroy');

    foreach (app(ExtranetRegistry::class)->routeGroups() as $routes) {
        $routes();
    }
});
// Alles andere auf diesem Host: 404 statt der Website. Ausgenommen der Backend-Pfad (Anmelden, Abmelden, Profil — der
// Rest leitet KeepCmsOffExtranet hierher), /up, die Passkeys (Registrieren im Profil, Anmelden; Hoang 27.10.2026: «404
// auf /user/passkeys/options») und die Live-Aktualisierung der Glocke: Laravel prüft Routen mit Host vor allen anderen
Route::domain(Extranet::host())->middleware('web')->match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '{path}', static fn () => abort(404))
    ->where('path', '(?!'.preg_quote(trim((string) config('basalto.backend.path', 'admin'), '/'), '/').'(?:/|$)|up(?:/|$)|user/passkeys(?:/|$)|passkeys(?:/|$)'
        .'|\.well-known/passkey-endpoints$|_live(?:/|$)).+')->name('extranet.missing');
