<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Backend\Dates;
use Basalto\Core\License\LicenseStatus;
use Basalto\Core\Operations\Operators;
use Basalto\Core\Saas\Saas;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Theme\ThemeStatus;
use Inertia\Inertia;
use Inertia\Response;

/** Modus Einrichtung (Mockup 9 unten): die rote Linie als Prüfbericht, rechts Theme, Lizenz und Betrieb. */
final class SetupController
{
    public function __construct(private readonly CurrentSite $current, private readonly ThemeStatus $themes, private readonly Operations $operations, private readonly Dates $dates) {}

    public function __invoke(): Response
    {
        $status = $this->themes->forSite($this->current->get());
        $theme = $status['theme'];
        $license = LicenseStatus::current();

        return Inertia::render('core::Setup', [
            'line' => $status['line'],
            'theme' => $theme === null ? null : [...$theme, 'checked_at' => $this->dates->dateTime($theme['checked_at'])],
            // TB 12 Scheibe 1: auf der SaaS keine Lizenzkarte (Lizenz im Abo, Plan unter Verwaltung › Konto)
            'license' => Saas::enabled() ? null : [...$license->toArray(), 'banner_from' => $this->dates->date($license->bannerFrom())],
            // TB 12 Scheibe 1: Betrieb der Instanz auf der SaaS nur für den Betreiber (GuardSaasInstanceAreas)
            'operations' => Saas::enabled() && ! Operators::allows(request()->user()) ? null : $this->operations->summary(),
            // TB 29: «Jetzt prüfen» lädt diese Seite neu — der Prüfbericht entsteht bei jedem Aufruf frisch
            'urls' => ['check' => route('basalto.setup'), 'publish' => route('basalto.setup.publish')],
        ]);
    }
}
