<?php

declare(strict_types=1);

use Basalto\Core\Backend\Backend;
use Basalto\Core\Help\HelpImages;
use Basalto\Core\Http\Controllers\CancellationController;
use Basalto\Core\Http\Controllers\DocsImageController;
use Basalto\Core\Http\Controllers\FormSubmissionController;
use Basalto\Core\Http\Controllers\FrontendController;
use Basalto\Core\Http\Controllers\FrontendEditorController;
use Basalto\Core\Http\Controllers\LegalController;
use Basalto\Core\Http\Controllers\LiveController;
use Basalto\Core\Http\Controllers\ProofOfWorkController;
use Basalto\Core\Http\Controllers\ThemeAssetController;
use Basalto\Core\Http\Controllers\UpController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

// Formulare (M-11): ohne CSRF-Token, weil Seiten mit Formular aus dem Seiten-Cache kommen — den Schutz tragen Honeypot,
// signiertes Zeit-Token und Limit je IP (Forms\SpamGuard); der Versand ändert nur, was ein Gast ohnehin darf.
Route::post('forms/{handle}', FormSubmissionController::class)->where('handle', '[a-z0-9_-]+')
    ->middleware(['web', 'basalto.site'])->withoutMiddleware(PreventRequestForgery::class)->name('basalto.forms.submit');

// Betrieb (K-17): Readiness mit Datenbank und Herzschlag, Liveness ohne Datenbank — ohne Sitzung, vor der Auslieferung
Route::get('up', [UpController::class, 'ready'])->name('basalto.up');
Route::get('up/live', [UpController::class, 'live'])->name('basalto.up.live');

// TB 11 Scheibe 3 (G-07): Rechtstexte öffentlich — ohne Sitzung, Cookies und Datenbank, damit der Installer sie
// schon vor der Datenbank verlinken kann; der Unterstrich hält den Pfad frei von Seiten einer Site (wie «_theme»)
// TB 29: Bilder der Doku (Screenshots, Schemas) — für die Hilfe im Backend und die Doku im Web
Route::get('_doku/{path}', DocsImageController::class)->where('path', HelpImages::PATTERN)->name('basalto.docs.image');
Route::get('_recht/{locale}/{text}', [LegalController::class, 'show'])->where(['locale' => '[a-z]{2}', 'text' => '[a-z0-9-]+'])->name('basalto.legal.public');

// CR-011 TB 59: «Verträge hier kündigen» (§ 312k BGB) — ohne Anmeldung und ohne CSRF wie die Formulare (Zeit-Token,
// Honigtopf, Limit je IP); Eingangsbestätigung und Zurücknehmen nur über signierte Adressen
Route::middleware(['web', 'basalto.site'])->withoutMiddleware(PreventRequestForgery::class)->group(static function (): void {
    Route::get('_kuendigen', [CancellationController::class, 'show'])->name('basalto.cancel');
    Route::post('_kuendigen', [CancellationController::class, 'store'])->middleware('throttle:20,1')->name('basalto.cancel.store');
    Route::get('_kuendigen/eingang/{cancellation}', [CancellationController::class, 'receipt'])->whereNumber('cancellation')->middleware('signed:relative')->name('basalto.cancel.receipt');
    Route::get('_kuendigen/zuruecknehmen/{cancellation}', [CancellationController::class, 'confirmRevoke'])->whereNumber('cancellation')->middleware('signed:relative')->name('basalto.cancel.revoke');
    Route::post('_kuendigen/zuruecknehmen/{cancellation}', [CancellationController::class, 'revoke'])->whereNumber('cancellation')->middleware('signed:relative')->name('basalto.cancel.revoke.store');
});

// CR-008: Live-Aktualisierung — ohne Sitzung und Cookies (keine Schreibzugriffe je Abfrage), begrenzt je Adresse
Route::get('_live', LiveController::class)->middleware('throttle:basalto-live')->name('basalto.live');
// TB 50: Aufgabe für den Spamschutz im Browser (Proof of Work)
Route::get('_pruefung', ProofOfWorkController::class)->middleware('throttle:30,1')->name('basalto.pow');
Route::post('_live/auth', [LiveController::class, 'auth'])->middleware('throttle:basalto-live')->name('basalto.live.auth');

// Dateien aus «assets/» eines Themes (L-10): ohne Sitzung und Cookies, damit Browser und CDN sie behalten dürfen
Route::get('_theme/{site}/{hash}/{path}', ThemeAssetController::class)->whereNumber('site')->where('hash', '[a-f0-9]{12}')
    ->where('path', 'assets/.+')->name('basalto.theme.asset');

// TB 10 Scheibe 4: Frontend-Editor (M-19) — unter dem Backend-Pfad, aber mit der Site aus dem Host wie die Auslieferung;
// Anmeldung und Recht prüft der Controller (Policy «editOnPage»), CSRF wie jede web-Anfrage (X-XSRF-TOKEN aus dem Cookie)
Route::middleware(['web', 'basalto.site'])->prefix(app(Backend::class)->path().'/seite-bearbeiten')->name('basalto.frontend-editor.')->group(static function (): void {
    Route::post('modus', [FrontendEditorController::class, 'mode'])->name('mode');
    Route::post('{entry}/{locale}', [FrontendEditorController::class, 'save'])->whereNumber('entry')->where('locale', '[a-z]{2,3}(-[a-z0-9]{2,4})?')->name('save');
    Route::get('{entry}/{locale}/backend', [FrontendEditorController::class, 'open'])->whereNumber('entry')->where('locale', '[a-z]{2,3}(-[a-z0-9]{2,4})?')->name('open');
});

// Die Auslieferung (M-01): Hostname → Site, Pfad mit Sprachpräfix → Seite, Inhalt, Weiterleitung oder Fehlerseite.
// Als Fallback-Route greift sie erst, wenn keine andere Route passt — auch solche, die nach dem Boot entstehen.
// TB 54: zählt Seitenaufrufe nach dem Senden der Antwort (Statistik)
Route::fallback(FrontendController::class)->middleware(['web', 'basalto.site', 'basalto.statistik'])->name('basalto.frontend');
