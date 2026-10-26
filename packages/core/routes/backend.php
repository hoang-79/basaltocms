<?php

declare(strict_types=1);

use Basalto\Core\Backend\Backend;
use Basalto\Core\Http\Controllers\ActivityController;
use Basalto\Core\Http\Controllers\BackendSearchController;
use Basalto\Core\Http\Controllers\BlockAssistController;
use Basalto\Core\Http\Controllers\ContentTypeController;
use Basalto\Core\Http\Controllers\DashboardController;
use Basalto\Core\Http\Controllers\DemoLoginController;
use Basalto\Core\Http\Controllers\DesignController;
use Basalto\Core\Http\Controllers\EntryController;
use Basalto\Core\Http\Controllers\FormController;
use Basalto\Core\Http\Controllers\FormInboxController;
use Basalto\Core\Http\Controllers\HelpController;
use Basalto\Core\Http\Controllers\ImageVariantController;
use Basalto\Core\Http\Controllers\InstallController;
use Basalto\Core\Http\Controllers\InvitationController;
use Basalto\Core\Http\Controllers\LayoutController;
use Basalto\Core\Http\Controllers\LegalController;
use Basalto\Core\Http\Controllers\LicenseAccountController;
use Basalto\Core\Http\Controllers\LicenseController;
use Basalto\Core\Http\Controllers\LoginController;
use Basalto\Core\Http\Controllers\MediaController;
use Basalto\Core\Http\Controllers\ModulesController;
use Basalto\Core\Http\Controllers\NavigationController;
use Basalto\Core\Http\Controllers\NotificationController;
use Basalto\Core\Http\Controllers\OperationsController;
use Basalto\Core\Http\Controllers\PageController;
use Basalto\Core\Http\Controllers\PasskeyController;
use Basalto\Core\Http\Controllers\PresenceController;
use Basalto\Core\Http\Controllers\PrivacyRequestsController;
use Basalto\Core\Http\Controllers\ProfileController;
use Basalto\Core\Http\Controllers\PublishController;
use Basalto\Core\Http\Controllers\RedirectController;
use Basalto\Core\Http\Controllers\RoleController;
use Basalto\Core\Http\Controllers\SettingsController;
use Basalto\Core\Http\Controllers\SetupController;
use Basalto\Core\Http\Controllers\SiteController;
use Basalto\Core\Http\Controllers\SiteExportController;
use Basalto\Core\Http\Controllers\SiteSwitchController;
use Basalto\Core\Http\Controllers\SnippetController;
use Basalto\Core\Http\Controllers\StatisticsController;
use Basalto\Core\Http\Controllers\StatusController;
use Basalto\Core\Http\Controllers\SupportOpenController;
use Basalto\Core\Http\Controllers\ThemeController;
use Basalto\Core\Http\Controllers\ThemeEditorController;
use Basalto\Core\Http\Controllers\TranslationController;
use Basalto\Core\Http\Controllers\UpdatesController;
use Basalto\Core\Http\Controllers\UserController;
use Basalto\Core\Http\Controllers\WorkflowController;
use Basalto\Core\Http\Middleware\HandleBackendRequests;
use Basalto\Core\Http\Middleware\HandleInstallRequests;
use Basalto\Core\Http\Middleware\PrepareInstallation;
use Basalto\Core\Http\Middleware\SelectGuestLocale;
use Illuminate\Support\Facades\Route;

// Installer im Browser (K-05): nur solange nichts eingerichtet ist; Sitzung über Dateien, Props ohne Datenbank.
Route::middleware([PrepareInstallation::class, 'web', SelectGuestLocale::class, HandleInstallRequests::class, 'throttle:basalto-install'])->prefix(app(Backend::class)->path())->name('basalto.')->group(static function (): void {
    Route::get('installieren', [InstallController::class, 'show'])->name('install');
    Route::post('installieren/datenbank', [InstallController::class, 'migrate'])->name('install.migrate');
    Route::post('installieren', [InstallController::class, 'store'])->name('install.store');
});
// «Fertig» liegt ausserhalb: dann ist die Installation schon eingerichtet und der Installer selbst zu
Route::middleware([PrepareInstallation::class.':fertig', 'web', HandleInstallRequests::class])->prefix(app(Backend::class)->path())->name('basalto.')->get('installieren/fertig', [InstallController::class, 'done'])->name('install.done');

// Anmeldung ohne Passwort (K-04): für Gäste, mit der Blade-Wurzel des Backends; Code-Anfrage und -Prüfung sind begrenzt.
// Sprache aus Accept-Language (N-01), bevor die geteilten Props und die Controller Texte erzeugen.
Route::middleware(['web', SelectGuestLocale::class, HandleBackendRequests::class])->prefix(app(Backend::class)->path())->name('basalto.')->group(static function (): void {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login/code', [LoginController::class, 'requestCode'])->middleware('throttle:basalto-login')->name('login.code');
    Route::get('login/verify', [LoginController::class, 'showCode'])->name('login.verify.show');
    Route::post('login/verify', [LoginController::class, 'verifyCode'])->middleware('throttle:basalto-login-verify')->name('login.verify');
    // TB 72: Code erneut senden (Wartezeit aus den Einstellungen, dazu dieselbe Drosselung wie die Code-Anfrage)
    Route::post('login/resend', [LoginController::class, 'resendCode'])->middleware('throttle:basalto-login')->name('login.resend');
    Route::get('login/{token}', [LoginController::class, 'link'])->where('token', '[A-Za-z0-9]{64}')->name('login.link');
    // TB 11 Scheibe 4 (G-10): Schnellzugang der Demo — ohne BASALTO_DEMO=true antwortet die Adresse mit 404
    // TB 16 Nachtrag: auch GET — aus dem Fenster im Frontend der Demo (Seiten aus dem Cache tragen kein CSRF-Token). Eine Route
    // für beide Methoden, sonst erzeugt Wayfinder für dieselbe Adresse zwei gleichnamige Schlüssel
    Route::match(['GET', 'POST'], 'login/demo/{role}', DemoLoginController::class)->where('role', 'betreiber|redaktion|uebersetzung')->middleware('throttle:basalto-login-verify')->name('login.demo');
    // Abmelden auch ohne gültige Sitzung (abgelaufen, Demo über Nacht zurückgesetzt): sonst schickt die Anmeldepflicht
    // auf die Anmeldung statt dorthin, wohin die Abmeldung führt (Demo: Startseite)
    Route::post('logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('einladung/{token}', [InvitationController::class, 'show'])->where('token', '[A-Za-z0-9]{64}')->name('invitation.show');
    Route::post('einladung/{token}', [InvitationController::class, 'accept'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:basalto-login')->name('invitation.accept');
});

app(Backend::class)->routes(static function (): void {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('einrichtung', SetupController::class)->name('setup');
    Route::get('einrichtung/veroeffentlichen', [PublishController::class, 'show'])->name('setup.publish');
    Route::post('einrichtung/veroeffentlichen', [PublishController::class, 'store'])->name('setup.publish.store');
    Route::post('einrichtung/rechtsvorlage/{kind}', [PublishController::class, 'legal'])->where('kind', 'impressum|datenschutz')->name('setup.legal');
    Route::get('einrichtung/themes', [ThemeController::class, 'index'])->name('setup.themes');
    Route::put('einrichtung/themes', [ThemeController::class, 'switch'])->name('setup.themes.switch');
    Route::get('einrichtung/themes/vorschau-beenden', [ThemeController::class, 'endPreview'])->name('setup.themes.preview.end');
    Route::get('einrichtung/themes/{name}/wirkung', [ThemeController::class, 'impact'])->where('name', '[a-z0-9][a-z0-9_-]*')->name('setup.themes.impact');
    Route::get('einrichtung/themes/{name}/vorschau', [ThemeController::class, 'preview'])->where('name', '[a-z0-9][a-z0-9_-]*')->name('setup.themes.preview');
    Route::get('einrichtung/themes/{name}/bild', [ThemeController::class, 'image'])->where('name', '[a-z0-9][a-z0-9_-]*')->name('setup.themes.image');
    Route::post('einrichtung/themes/hochladen', [ThemeController::class, 'upload'])->name('setup.themes.upload');
    Route::post('einrichtung/themes/kopie', [ThemeController::class, 'copy'])->name('setup.themes.copy');
    Route::get('einrichtung/themes/{name}/paket', [ThemeController::class, 'export'])->where('name', '[a-z0-9][a-z0-9_-]*')->name('setup.themes.export');
    Route::put('einrichtung/themes/{name}/ausblenden', [ThemeController::class, 'hide'])->where('name', '[a-z0-9][a-z0-9_-]*')->name('setup.themes.hide');
    Route::delete('einrichtung/themes/{name}', [ThemeController::class, 'destroy'])->where('name', '[a-z0-9][a-z0-9_-]*')->name('setup.themes.destroy');
    Route::get('einrichtung/theme-editor', [ThemeEditorController::class, 'index'])->name('setup.theme-editor');
    Route::post('einrichtung/theme-editor/uebernehmen', [ThemeEditorController::class, 'adopt'])->name('setup.theme-editor.adopt');
    Route::post('einrichtung/theme-editor/zurueck-zum-ordner', [ThemeEditorController::class, 'release'])->name('setup.theme-editor.release');
    Route::put('einrichtung/theme-editor/datei', [ThemeEditorController::class, 'save'])->name('setup.theme-editor.save');
    Route::post('einrichtung/theme-editor/verwerfen', [ThemeEditorController::class, 'discard'])->name('setup.theme-editor.discard');
    Route::post('einrichtung/theme-editor/einreichen', [ThemeEditorController::class, 'submit'])->name('setup.theme-editor.submit');
    Route::post('einrichtung/theme-editor/veroeffentlichen', [ThemeEditorController::class, 'publish'])->name('setup.theme-editor.publish');
    Route::post('einrichtung/theme-editor/fassungen/{version}', [ThemeEditorController::class, 'restore'])->whereNumber('version')->name('setup.theme-editor.restore');
    Route::post('einrichtung/theme-editor/import', [ThemeEditorController::class, 'import'])->name('setup.theme-editor.import');
    Route::get('einrichtung/theme-editor/export', [ThemeEditorController::class, 'export'])->name('setup.theme-editor.export');
    Route::get('einrichtung/theme-editor/vorschau', [ThemeEditorController::class, 'preview'])->name('setup.theme-editor.preview');
    Route::get('einrichtung/theme-editor/vorschau-seite', [ThemeEditorController::class, 'previewPage'])->name('setup.theme-editor.preview.page');
    Route::get('einrichtung/theme-editor/vorschau-beenden', [ThemeEditorController::class, 'endPreview'])->name('setup.theme-editor.preview.end');
    Route::post('einrichtung/theme-editor/bild', [ThemeEditorController::class, 'storeAsset'])->name('setup.theme-editor.asset');
    Route::delete('einrichtung/theme-editor/bild', [ThemeEditorController::class, 'destroyAsset'])->name('setup.theme-editor.asset.destroy');
    Route::post('einrichtung/lava/sprachdienst', [ThemeEditorController::class, 'language'])->middleware('throttle:240,1')->name('setup.lava.language');
    Route::get('einrichtung/design', [DesignController::class, 'show'])->name('setup.design');
    Route::put('einrichtung/design', [DesignController::class, 'update'])->name('setup.design.update');
    Route::delete('einrichtung/design', [DesignController::class, 'reset'])->name('setup.design.reset');
    Route::get('einrichtung/layouts', LayoutController::class)->name('setup.layouts.index');
    // TB 10 Scheibe 3 (M-28): Layout-Editor, Lava-Teile, Templates, Bildvarianten, Bausteine — Änderungen als Theme-Entwurf
    Route::post('einrichtung/layouts', [LayoutController::class, 'storeLayout'])->name('setup.layouts.store');
    Route::post('einrichtung/layouts/probe', [LayoutController::class, 'probe'])->middleware('throttle:240,1')->name('setup.layouts.probe');
    Route::put('einrichtung/layouts/{name}', [LayoutController::class, 'updateLayout'])->where('name', '[a-z][a-z0-9_]*')->name('setup.layouts.update');
    Route::delete('einrichtung/layouts/{name}', [LayoutController::class, 'destroyLayout'])->where('name', '[a-z][a-z0-9_]*')->name('setup.layouts.destroy');
    Route::post('einrichtung/teile', [LayoutController::class, 'storePart'])->name('setup.parts.store');
    Route::put('einrichtung/teile/{name}', [LayoutController::class, 'updatePart'])->where('name', '[a-z][a-z0-9_]*')->name('setup.parts.update');
    Route::delete('einrichtung/teile/{name}', [LayoutController::class, 'destroyPart'])->where('name', '[a-z][a-z0-9_]*')->name('setup.parts.destroy');
    Route::put('einrichtung/templates/{name}', [LayoutController::class, 'updateTemplate'])->where('name', '[a-z_][a-z0-9_-]*')->name('setup.templates.update');
    Route::get('einrichtung/bildvarianten', [ImageVariantController::class, 'index'])->name('setup.variants.index');
    Route::post('einrichtung/bildvarianten', [ImageVariantController::class, 'store'])->name('setup.variants.store');
    Route::put('einrichtung/bildvarianten/{name}', [ImageVariantController::class, 'update'])->where('name', '[a-z][a-z0-9-]*')->name('setup.variants.update');
    Route::delete('einrichtung/bildvarianten/{name}', [ImageVariantController::class, 'destroy'])->where('name', '[a-z][a-z0-9-]*')->name('setup.variants.destroy');
    Route::get('einrichtung/bausteine', [SnippetController::class, 'index'])->name('setup.snippets.index');
    Route::post('einrichtung/bausteine', [SnippetController::class, 'store'])->name('setup.snippets.store');
    Route::put('einrichtung/bausteine/{snippet}', [SnippetController::class, 'update'])->whereNumber('snippet')->name('setup.snippets.update');
    Route::delete('einrichtung/bausteine/{snippet}', [SnippetController::class, 'destroy'])->whereNumber('snippet')->name('setup.snippets.destroy');
    Route::post('einrichtung/bausteine/{snippet}/aufloesen', [SnippetController::class, 'dissolve'])->whereNumber('snippet')->name('setup.snippets.dissolve');
    Route::get('bausteine/auswahl', [SnippetController::class, 'options'])->name('snippets.options');
    // TB 30: geführtes Blockformular — Linkziele und Live-Vorschau beliebiger Blöcke
    Route::get('editor/linkziele', [BlockAssistController::class, 'links'])->name('editor.links');
    Route::get('editor/theme-bilder', [BlockAssistController::class, 'themeImages'])->name('editor.theme-images');
    // TB 49: wer einen Inhalt gerade bearbeitet
    Route::post('editor/praesenz/{entry}', PresenceController::class)->whereNumber('entry')->middleware('throttle:60,1')->name('editor.presence');
    Route::post('editor/vorschau', [BlockAssistController::class, 'preview'])->name('editor.preview');
    Route::get('einrichtung/navigation', [NavigationController::class, 'index'])->name('setup.navigation.index');
    Route::post('einrichtung/navigation/{nav}/eintraege', [NavigationController::class, 'store'])->where('nav', '[a-z][a-z0-9_-]*')->name('setup.navigation.store');
    Route::post('einrichtung/navigation/{nav}/reihenfolge', [NavigationController::class, 'order'])->where('nav', '[a-z][a-z0-9_-]*')->name('setup.navigation.order');
    Route::put('einrichtung/navigation/eintraege/{item}', [NavigationController::class, 'update'])->whereNumber('item')->name('setup.navigation.update');
    Route::delete('einrichtung/navigation/eintraege/{item}', [NavigationController::class, 'destroy'])->whereNumber('item')->name('setup.navigation.destroy');
    Route::get('einrichtung/typen', [ContentTypeController::class, 'index'])->name('setup.types.index');
    Route::get('einrichtung/typen/neu', [ContentTypeController::class, 'create'])->name('setup.types.create');
    Route::post('einrichtung/typen', [ContentTypeController::class, 'store'])->name('setup.types.store');
    Route::get('einrichtung/typen/{name}', [ContentTypeController::class, 'edit'])->where('name', '[a-z][a-z0-9-]*')->name('setup.types.edit');
    Route::put('einrichtung/typen/{name}', [ContentTypeController::class, 'update'])->where('name', '[a-z][a-z0-9-]*')->name('setup.types.update');
    Route::delete('einrichtung/typen/{name}', [ContentTypeController::class, 'destroy'])->where('name', '[a-z][a-z0-9-]*')->name('setup.types.destroy');
    Route::get('sites', [SiteController::class, 'index'])->name('sites.index');
    Route::get('sites/neu', [SiteController::class, 'create'])->name('sites.create');
    Route::post('sites', [SiteController::class, 'store'])->name('sites.store');
    Route::get('sites/{site}', [SiteController::class, 'edit'])->name('sites.edit');
    Route::put('sites/{site}', [SiteController::class, 'update'])->name('sites.update');
    Route::get('sites/{site}/export', SiteExportController::class)->name('sites.export');
    // TB 11 Scheibe 2 (M-20): Verwaltung › Module mit Reiter «Marktplatz» (Katalog von basaltocms.com, «Theme übernehmen»)
    Route::get('module', [ModulesController::class, 'index'])->name('modules');
    Route::post('module/marktplatz/theme', [ModulesController::class, 'installTheme'])->name('modules.marketplace.theme');
    // TB 24: Anprobe aus dem Katalog — Theme in die Bibliothek holen und mit den eigenen Inhalten ansehen (nur in der Sitzung)
    Route::get('module/marktplatz/anprobe', [ModulesController::class, 'tryOn'])->name('modules.marketplace.try');
    Route::post('module/marktplatz/anprobe', [ModulesController::class, 'startTryOn'])->name('modules.marketplace.try.start');
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::put('users/{user}/role', [UserController::class, 'updateRole'])->whereNumber('user')->name('users.role');
    Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->whereNumber('user')->name('users.deactivate');
    Route::post('users/{user}/activate', [UserController::class, 'activate'])->whereNumber('user')->name('users.activate');
    Route::delete('users/{user}/sessions', [UserController::class, 'endSessions'])->whereNumber('user')->name('users.sessions.end');
    Route::get('users/{user}/auskunft', [UserController::class, 'export'])->whereNumber('user')->name('users.export');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->whereNumber('user')->name('users.destroy');
    Route::post('invitations', [UserController::class, 'invite'])->name('invitations.store');
    // CR-005 (R-08): Rollen-Editor
    Route::get('users/rollen', [RoleController::class, 'index'])->name('roles.index');
    Route::post('users/rollen', [RoleController::class, 'store'])->name('roles.store');
    Route::put('users/rollen/{role}', [RoleController::class, 'update'])->where('role', '[a-z0-9_]+')->name('roles.update');
    Route::post('users/rollen/{role}/zuruecksetzen', [RoleController::class, 'reset'])->where('role', '[a-z0-9_]+')->name('roles.reset');
    Route::delete('users/rollen/{role}', [RoleController::class, 'destroy'])->where('role', '[a-z0-9_]+')->name('roles.destroy');
    Route::post('invitations/{invitation}/resend', [UserController::class, 'resend'])->whereNumber('invitation')->name('invitations.resend');
    Route::delete('invitations/{invitation}', [UserController::class, 'revoke'])->whereNumber('invitation')->name('invitations.revoke');
    Route::get('profil', [ProfileController::class, 'show'])->name('profile');
    // TB 27: Einstellungen der Benachrichtigungen (Reiter im Profil), Glocke und Center
    Route::put('profil/benachrichtigungen', [NotificationController::class, 'settings'])->name('profile.notifications');
    Route::get('benachrichtigungen', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('benachrichtigungen/glocke', [NotificationController::class, 'bell'])->name('notifications.bell');
    Route::get('benachrichtigungen/weiter', [NotificationController::class, 'follow'])->middleware('signed')->name('notifications.follow');
    Route::post('benachrichtigungen/alle-gelesen', [NotificationController::class, 'readAll'])->name('notifications.read_all');
    Route::delete('benachrichtigungen/gelesene', [NotificationController::class, 'destroyRead'])->name('notifications.destroy_read');
    Route::get('benachrichtigungen/{notification}', [NotificationController::class, 'open'])->whereUuid('notification')->name('notifications.open');
    Route::post('benachrichtigungen/{notification}/gelesen', [NotificationController::class, 'read'])->whereUuid('notification')->name('notifications.read');
    Route::delete('benachrichtigungen/{notification}', [NotificationController::class, 'destroy'])->whereUuid('notification')->name('notifications.destroy');
    Route::put('profil', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profil/hilfe-breite', [ProfileController::class, 'helpWidth'])->name('profile.help-width');
    Route::delete('profil/sitzungen', [ProfileController::class, 'endOthers'])->name('profile.sessions.others');
    Route::get('profil/auskunft', [ProfileController::class, 'export'])->name('profile.export');
    // TB 21: Profilbild hochladen, entfernen, ausliefern (für alle Angemeldeten — die Benutzerliste zeigt es auch)
    Route::post('profil/avatar', [ProfileController::class, 'storeAvatar'])->name('profile.avatar.store');
    Route::delete('profil/avatar', [ProfileController::class, 'destroyAvatar'])->name('profile.avatar.destroy');
    Route::get('profil/avatar/{user}/{file}', [ProfileController::class, 'avatar'])->whereNumber('user')->where('file', '[a-z0-9]+\.webp')->name('profile.avatar');
    Route::delete('profil/sitzungen/{session}', [ProfileController::class, 'end'])->where('session', '[A-Za-z0-9_]+')->name('profile.sessions.end');
    Route::post('profil/api-tokens', [ProfileController::class, 'storeToken'])->name('profile.tokens.store');
    Route::delete('profil/api-tokens/{token}', [ProfileController::class, 'destroyToken'])->whereNumber('token')->name('profile.tokens.destroy');
    Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');
    // TB 54: Statistik der Website (Seitenaufrufe, wie Fristo)
    Route::get('statistik', StatisticsController::class)->name('statistics');
    // CR-011 TB 61: Anliegen zum Datenschutz aus dem Kundenkonto
    Route::get('datenschutz', [PrivacyRequestsController::class, 'index'])->name('privacy.requests.index');
    Route::post('datenschutz/{privacyRequest}/erledigen', [PrivacyRequestsController::class, 'answer'])->whereNumber('privacyRequest')->name('privacy.requests.answer');
    Route::get('activity/export', [ActivityController::class, 'export'])->name('activity.export');
    Route::get('lizenz', [LicenseController::class, 'show'])->name('license');
    Route::post('lizenz/pruefen', [LicenseController::class, 'check'])->name('license.check');
    Route::put('lizenz', [LicenseController::class, 'store'])->name('license.store');
    Route::delete('lizenz', [LicenseController::class, 'destroy'])->name('license.destroy');
    // TB 15 Scheibe 4 (CR-004 P-06): Kundenkonto beim Hersteller — verbinden per Mail-Code, Schlüssel übernehmen, trennen
    Route::post('lizenz/konto/code', [LicenseAccountController::class, 'code'])->name('license.account.code');
    Route::delete('lizenz/konto/code', [LicenseAccountController::class, 'restart'])->name('license.account.restart');
    Route::post('lizenz/konto/code/erneut', [LicenseAccountController::class, 'resend'])->name('license.account.resend');
    Route::post('lizenz/konto', [LicenseAccountController::class, 'connect'])->name('license.account.connect');
    Route::delete('lizenz/konto', [LicenseAccountController::class, 'disconnect'])->name('license.account.disconnect');
    Route::put('lizenz/konto/schluessel', [LicenseAccountController::class, 'useKey'])->name('license.account.key');
    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('settings/revisions/{revision}/restore', [SettingsController::class, 'restore'])->whereNumber('revision')->name('settings.restore');
    Route::get('passkeys', PasskeyController::class)->name('passkeys');
    // TB 19 (P-16): Support-Zugriff aus dem Extranet öffnen — wählt die Kunden-Site, danach nur lesend
    Route::get('support/{session}', SupportOpenController::class)->whereNumber('session')->name('support.open');
    Route::get('status', StatusController::class)->name('status');
    Route::get('pages', [PageController::class, 'index'])->name('pages.index');
    Route::get('pages/neu', [PageController::class, 'create'])->name('pages.create');
    Route::post('pages', [PageController::class, 'store'])->name('pages.store');
    Route::get('pages/{entry}', [PageController::class, 'edit'])->whereNumber('entry')->name('pages.edit');
    Route::put('pages/{entry}', [PageController::class, 'update'])->whereNumber('entry')->name('pages.update');
    Route::post('pages/{entry}/move', [PageController::class, 'move'])->whereNumber('entry')->name('pages.move');
    Route::delete('pages/{entry}', [PageController::class, 'destroy'])->whereNumber('entry')->name('pages.destroy');
    Route::post('pages/{id}/restore', [PageController::class, 'restore'])->whereNumber('id')->name('pages.restore');
    Route::post('pages/{entry}/copy', [PageController::class, 'copy'])->whereNumber('entry')->name('pages.copy');
    Route::post('pages/bulk', [PageController::class, 'bulk'])->name('pages.bulk');
    Route::get('suche', BackendSearchController::class)->name('search');
    // TB 10 Scheibe 6 (N-09): Redaktions-Hilfe aus docs/redaktion/
    // TB 20 (Webhilfe): Panel und Suche als JSON — vor der Seite, sonst wäre «suche» ein Seitenname
    Route::get('hilfe/suche', [HelpController::class, 'search'])->name('help.search');
    Route::get('hilfe/panel/{page?}', [HelpController::class, 'panel'])->where('page', '[a-z0-9-]+')->name('help.panel');
    Route::get('hilfe/{page?}', HelpController::class)->where('page', '[a-z0-9-]+')->name('help');
    // TB 11 Scheibe 3 (G-07): Rechtstexte aus docs/recht/ in der Sprache der Oberfläche, andere über ?sprache=
    Route::get('recht/{text?}', [LegalController::class, 'backend'])->where('text', '[a-z0-9-]+')->name('legal');
    // Formulare (M-11): Liste, Baukasten (forms.lava), Eingänge
    Route::get('formulare', [FormController::class, 'index'])->name('forms.index');
    Route::get('formulare/neu', [FormController::class, 'create'])->name('forms.create');
    Route::post('formulare', [FormController::class, 'store'])->name('forms.store');
    Route::post('formulare/vorschau', [FormController::class, 'preview'])->name('forms.preview');
    Route::get('formulare/{handle}', [FormController::class, 'edit'])->where('handle', '[a-z0-9_-]+')->name('forms.edit');
    Route::put('formulare/{handle}', [FormController::class, 'update'])->where('handle', '[a-z0-9_-]+')->name('forms.update');
    Route::delete('formulare/{handle}', [FormController::class, 'destroy'])->where('handle', '[a-z0-9_-]+')->name('forms.destroy');
    Route::post('formulare/{handle}/geheimnis', [FormController::class, 'secret'])->where('handle', '[a-z0-9_-]+')->name('forms.secret');
    Route::get('formulare/{handle}/eingaenge', [FormInboxController::class, 'index'])->where('handle', '[a-z0-9_-]+')->name('forms.inbox');
    Route::get('formulare/{handle}/eingaenge/export', [FormInboxController::class, 'export'])->where('handle', '[a-z0-9_-]+')->name('forms.inbox.export');
    Route::post('formulare/{handle}/eingaenge/sammel', [FormInboxController::class, 'bulk'])->where('handle', '[a-z0-9_-]+')->name('forms.inbox.bulk');
    Route::get('formulare/{handle}/eingaenge/{submission}', [FormInboxController::class, 'show'])->where('handle', '[a-z0-9_-]+')->whereNumber('submission')->name('forms.inbox.show');
    Route::put('formulare/{handle}/eingaenge/{submission}', [FormInboxController::class, 'update'])->where('handle', '[a-z0-9_-]+')->whereNumber('submission')->name('forms.inbox.update');
    Route::delete('formulare/{handle}/eingaenge/{submission}', [FormInboxController::class, 'destroy'])->where('handle', '[a-z0-9_-]+')->whereNumber('submission')->name('forms.inbox.destroy');
    Route::post('formulare/{handle}/eingaenge/{submission}/senden', [FormInboxController::class, 'resend'])->where('handle', '[a-z0-9_-]+')->whereNumber('submission')->name('forms.inbox.resend');
    Route::get('formulare/{handle}/eingaenge/{submission}/datei/{field}', [FormInboxController::class, 'file'])->where('handle', '[a-z0-9_-]+')->whereNumber('submission')->name('forms.inbox.file');
    Route::post('pages/redirects', [RedirectController::class, 'store'])->name('pages.redirects.store');
    Route::post('pages/redirects/import', [RedirectController::class, 'import'])->name('pages.redirects.import');
    Route::put('pages/redirects/{redirect}', [RedirectController::class, 'update'])->whereNumber('redirect')->name('pages.redirects.update');
    Route::delete('pages/redirects/{redirect}', [RedirectController::class, 'destroy'])->whereNumber('redirect')->name('pages.redirects.destroy');
    Route::delete('pages/not-found/{hit}', [RedirectController::class, 'dismissHit'])->whereNumber('hit')->name('pages.not_found.destroy');
    Route::get('entries', [EntryController::class, 'index'])->name('entries.index');
    Route::post('entries', [EntryController::class, 'store'])->name('entries.store');
    Route::get('entries/{entry}/preview/{version}', [EntryController::class, 'preview'])->name('entries.preview');
    Route::post('entries/{entry}/{locale}/preview', [EntryController::class, 'previewLive'])->name('entries.preview.live');
    Route::post('entries/{entry}/{locale}/share', [EntryController::class, 'share'])->name('entries.share');
    Route::post('entries/{entry}/{locale}/restore/{version}', [EntryController::class, 'restore'])->name('entries.restore');
    Route::get('freigabe', [WorkflowController::class, 'index'])->name('reviews.index');
    Route::get('betrieb', [OperationsController::class, 'index'])->name('operations');
    // CR-007: Einstellungen › Updates
    Route::get('updates', [UpdatesController::class, 'index'])->name('updates');
    Route::get('updates/stand', [UpdatesController::class, 'status'])->name('updates.status');
    Route::post('updates/pruefen', [UpdatesController::class, 'check'])->name('updates.check');
    Route::post('updates/kern', [UpdatesController::class, 'core'])->name('updates.core');
    Route::post('updates/plugin', [UpdatesController::class, 'plugin'])->name('updates.plugin');
    Route::post('updates/theme', [UpdatesController::class, 'theme'])->name('updates.theme');
    Route::post('betrieb/backup', [OperationsController::class, 'backup'])->name('operations.backup');
    Route::post('betrieb/jobs/{uuid}/retry', [OperationsController::class, 'retry'])->whereUuid('uuid')->name('operations.jobs.retry');
    Route::delete('betrieb/jobs/{uuid}', [OperationsController::class, 'forget'])->whereUuid('uuid')->name('operations.jobs.forget');
    Route::get('entries/{entry}/{locale}/freigabe', [WorkflowController::class, 'show'])->name('entries.review');
    Route::post('entries/{entry}/{locale}/workflow/{action}', [WorkflowController::class, 'transition'])->whereIn('action', ['withdraw', 'approve', 'reject', 'schedule', 'unschedule', 'publish', 'unpublish'])->name('entries.workflow');
    Route::get('uebersetzung', [TranslationController::class, 'index'])->name('translations.index');
    Route::get('entries/{entry}/{locale}/uebersetzen', [TranslationController::class, 'edit'])->name('entries.translation');
    Route::post('entries/{entry}/{locale}/uebersetzen', [TranslationController::class, 'save'])->name('entries.translation.save');
    Route::get('entries/{entry}/{locale}', [EntryController::class, 'edit'])->name('entries.edit');
    Route::post('entries/{entry}/{locale}', [EntryController::class, 'update'])->name('entries.update');
    Route::post('entries/{entry}/{locale}/submit', [EntryController::class, 'submit'])->name('entries.submit');
    Route::post('entries/{entry}/{locale}/translate', [EntryController::class, 'translate'])->name('entries.translate');
    Route::post('site/{site}', SiteSwitchController::class)->name('site.switch');
    Route::get('media', [MediaController::class, 'index'])->name('media.index');
    Route::post('media', [MediaController::class, 'store'])->name('media.store');
    Route::post('media/paste', [MediaController::class, 'paste'])->name('media.paste');
    Route::post('media/folders', [MediaController::class, 'storeFolder'])->name('media.folders.store');
    Route::put('media/folders/{folder}', [MediaController::class, 'updateFolder'])->whereNumber('folder')->name('media.folders.update');
    Route::delete('media/folders/{folder}', [MediaController::class, 'destroyFolder'])->whereNumber('folder')->name('media.folders.destroy');
    Route::put('media/{media}', [MediaController::class, 'update'])->whereNumber('media')->name('media.update');
    Route::delete('media/{media}', [MediaController::class, 'destroy'])->whereNumber('media')->name('media.destroy');
    Route::post('media/{media}/restore', [MediaController::class, 'restore'])->whereNumber('media')->withTrashed()->name('media.restore');
    Route::post('media/{media}/replace', [MediaController::class, 'replace'])->whereNumber('media')->name('media.replace');
    Route::get('media/{media}/download', [MediaController::class, 'download'])->whereNumber('media')->withTrashed()->name('media.download');
    Route::get('media/{media}/{variant?}', [MediaController::class, 'show'])->whereNumber('media')->withTrashed()->name('media.show');
});

// Geteilte Vorschau (M-05): ohne Anmeldung, nur mit gültiger Signatur (72 h), nie im Index.
Route::middleware(['web', 'signed'])->get(app(Backend::class)->path().'/vorschau/{entry}/{locale}', [EntryController::class, 'shared'])->whereNumber('entry')->name('basalto.entries.shared');
