<?php

declare(strict_types=1);

namespace Basalto\Modules\Members;

use Basalto\Core\Access\Areas;
use Basalto\Core\Access\Level;
use Basalto\Core\Backend\Backend;
use Basalto\Core\Content\PageAccess;
use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\FrontendAccounts\Events\CustomerSignedIn;
use Basalto\Core\Models\Site;
use Basalto\Core\Notifications\NotificationTypes;
use Basalto\Core\Testdata\Testdata;
use Basalto\Core\Testdata\TestdataRun;
use Basalto\Lava\Runtime\Context;
use Basalto\Lava\Runtime\Limits;
use Basalto\Lava\Runtime\Registry;
use Basalto\Modules\Members\Frontend\MemberMarkup;
use Basalto\Modules\Members\Http\Controllers\Backend\MembersController;
use Basalto\Modules\Members\Http\Controllers\JoinController;
use Basalto\Modules\Members\Listeners\JoinOnSignIn;
use Basalto\Modules\Members\Models\Member;
use Basalto\Modules\Members\Privacy\MemberPersonData;
use Basalto\Modules\Members\Testdata\MembersTestdata;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Mitglieder als Modul (TB 13 Scheibe 2, M-23), je Site schaltbar und ab Werk aus:
 *
 * - **Frontend-Konto des Kerns** (`CustomerAuth::useFor('members')`): Anmeldung und Registrierung per Mail-Code ohne
 *   Passwort; wer sich über die Anmeldung der Mitglieder anmeldet, tritt bei — offen sofort, mit Freigabe wartend,
 *   nur auf Einladung gar nicht (dann geht ein Code nur an eingeladene Adressen);
 * - **geschützte Seiten**: der Kern schützt (Reiter «Zugriff», vererbt), dieses Modul sagt, wer sie sehen darf
 *   (`MembersGate`: aktive Mitglieder) und was Gäste stattdessen sehen (Anmeldung);
 * - **Lava-Tags** `members.login`, `members.account`, `members.status` (auch als Teile);
 * - **Backend** unter Inhalte › Mitglieder: Liste, Einladung, Freigabe, Sperre, Löschung, Export, Einstellungen;
 * - **Datenschutz**: Auskunft und Löschung je E-Mail (`basalto.person-data`).
 */
final class MembersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Testdaten (basalto:testdaten)
        app(Testdata::class)->register(MembersTestdata::KEY, 'Mitglieder', 20, static fn (Site $site): bool => app(Backend::class)->moduleEnabled('members', $site),
            static fn (TestdataRun $run) => app(MembersTestdata::class)->seed($run), static fn (Site $site) => app(MembersTestdata::class)->remove($site));
        // CR-005 (R-01): Bereich des Moduls und seine Routen
        app(Areas::class)->register('members', 'Mitglieder', Areas::CONTENT, 'members')->map('basalto.members.', 'members')->route('basalto.members.settings', 'members', Level::All)->route('basalto.members.export', 'members', Level::All);
        // TB 27: neue Mitgliedschaften, die auf eine Freigabe warten
        app(NotificationTypes::class)->type('members.pending', 'Neue Mitgliedschaft wartet auf Freigabe', 'Mitglieder', 'Nur wenn die Site Mitgliedschaften erst nach Freigabe öffnet.',
            area: 'members', level: Level::Edit, mail: true, icon: 'users');
        $root = dirname(__DIR__);
        $this->loadTranslationsFrom($root.'/lang', 'members');
        $this->loadJsonTranslationsFrom($root.'/lang');
        $this->loadMigrationsFrom($root.'/database/migrations');

        CustomerAuth::useFor(Members::MODULE, MembersRegistration::class);
        PageAccess::useGate(MembersGate::class);
        Event::listen(CustomerSignedIn::class, JoinOnSignIn::class);

        $backend = $this->app->make(Backend::class);
        $backend->module(Members::MODULE, 'members::backend.module', switchable: true, composerJson: $root.'/composer.json', enabledByDefault: false);
        $backend->pages('members', $root.'/resources/js/pages');
        $backend->menu('members::backend.title', 'basalto.members.index', 62, Backend::MODE_CONTENT, module: Members::MODULE, icon: 'users');
        $backend->count('basalto.members.index', static fn (Site $site): int => Member::query()->withoutGlobalScopes()->where('site_id', $site->id)
            ->where('status', MemberStatus::Pending->value)->count());
        $backend->routes(static function (): void {
            Route::get('mitglieder', [MembersController::class, 'index'])->name('members.index');
            Route::get('mitglieder/export', [MembersController::class, 'export'])->name('members.export');
            Route::post('mitglieder/einladen', [MembersController::class, 'invite'])->name('members.invite');
            Route::post('mitglieder/einstellungen', [MembersController::class, 'settings'])->name('members.settings');
            Route::post('mitglieder/sammelaktion', [MembersController::class, 'bulk'])->name('members.bulk');
            Route::post('mitglieder/{member}/status', [MembersController::class, 'status'])->whereNumber('member')->name('members.status');
            Route::delete('mitglieder/{member}', [MembersController::class, 'destroy'])->whereNumber('member')->name('members.destroy');
        });

        // «Mitglied werden» für schon Angemeldete — mit CSRF-Token (angemeldete Seiten kommen nie aus dem Cache)
        Route::middleware(['web', 'basalto.site', CustomerAuth::MIDDLEWARE])->group(static function (): void {
            Route::post('members/join', JoinController::class)->name('basalto.members.join');
        });

        // Lava: «{% members.login %}», «{% members.account %}», «{% members.status login: "/mitglieder" %}» — auch als Teile (L-12, M-28)
        $this->app->extend(Registry::class, static function (Registry $registry): Registry {
            $tags = [
                'members.login' => ['Anmeldung für Mitglieder', [], 'loginTag'],
                'members.account' => ['Mitgliedschaft', [], 'accountTag'],
                'members.status' => ['Anmelden / Abmelden', ['login'], 'statusTag'],
            ];
            $module = $registry->module(Members::MODULE);
            foreach ($tags as $name => [$label, $settings, $method]) {
                $module->tag($name, static fn (array $args, array $named, Context $ctx): string => app(MemberMarkup::class)->{$method}($named, $ctx))
                    ->part($name, $label, $settings, static fn (array $values, array $vars, string $lang): string => app(MemberMarkup::class)->{$method}($values, new Context($vars, app(Registry::class), new Limits)));
            }

            return $registry;
        });

        // Datenschutz des Kerns (N-05): Auskunft und Löschung je E-Mail — vor der Anonymisierung des Kontos durch den Kern
        $this->app->instance('basalto.person-data.members', (new MemberPersonData)(...));
        $this->app->tag(['basalto.person-data.members'], 'basalto.person-data');
    }
}
