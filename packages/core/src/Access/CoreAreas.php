<?php

declare(strict_types=1);

namespace Basalto\Core\Access;

/**
 * Die Bereiche des Kerns und ihre Routen (CR-005, R-01). Module melden ihre eigenen in ihrem Service-Provider an.
 * Labels sind deutsche Texte (Schlüssel der Übersetzung).
 */
final class CoreAreas
{
    public static function register(Areas $areas): void
    {
        $areas->register('pages', 'Seiten und Inhalte', Areas::CONTENT)
            ->register('media', 'Medien', Areas::CONTENT)
            ->register('translations', 'Übersetzung', Areas::CONTENT)
            ->register('approval', 'Freigabe und Veröffentlichen', Areas::CONTENT)
            ->register('forms', 'Formulare und Eingänge', Areas::CONTENT)
            ->register('navigation', 'Navigation', Areas::SETUP)
            ->register('design', 'Design, Themes und Layouts', Areas::SETUP)
            ->register('structure', 'Typen und Bausteine', Areas::SETUP)
            ->register('publishing', 'Veröffentlichen, SEO und Umleitungen', Areas::SETUP)
            ->register('users', 'Benutzer und Rollen', Areas::ADMIN)
            ->register('settings', 'Einstellungen, Module und Sites', Areas::ADMIN)
            ->register('license', 'Lizenz', Areas::ADMIN)
            ->register('operations', 'Betrieb', Areas::ADMIN)
            ->register('updates', 'Updates', Areas::ADMIN)
            ->register('activity', 'Protokoll', Areas::ADMIN)
            // TB 54: Statistik der Website
            ->register('statistics', 'Statistik', Areas::ADMIN)
            // CR-011 TB 61: Anliegen zum Datenschutz aus dem Kundenkonto
            ->register('privacy', 'Datenschutz-Anliegen', Areas::ADMIN);

        // Inhalte
        $areas->map('basalto.pages.', 'pages')
            ->map('basalto.pages.redirects.', 'publishing')
            ->map('basalto.pages.not_found.', 'publishing')
            ->map('basalto.entries.', 'pages')
            ->map('basalto.entries.translat', 'translations')
            ->map('basalto.snippets.', 'pages')
            ->map('basalto.reviews.', 'approval')
            ->map('basalto.translations.', 'translations')
            ->map('basalto.media.', 'media')
            ->map('basalto.forms.', 'forms')
            ->route('basalto.forms.secret', 'forms', Level::All)
            // TB 31: die Vorschau speichert nichts — ansehen genügt (auch Theme-Formulare lassen sich so ansehen)
            ->route('basalto.forms.preview', 'forms', Level::View);
        // Einreichen, Freigeben, Zurückweisen, Veröffentlichen: die Route verlangt nur «ansehen», die Policy entscheidet
        // nach Bereich (Seiten bearbeiten bzw. Freigabe) — sonst stünde die Freigabe vor der Tür ihrer eigenen Aufgabe
        $areas->route('basalto.entries.workflow', 'pages', Level::View)
            ->route('basalto.entries.review', 'pages', Level::View);
        // Einrichtung: die Übersicht ist offen, sobald ein Bereich der Einrichtung sichtbar ist
        $areas->groupRoute('basalto.setup', Areas::SETUP);
        foreach (['design', 'themes', 'theme-editor', 'layouts', 'parts', 'templates', 'variants', 'lava'] as $part) {
            $areas->map('basalto.setup.'.$part, 'design');
        }
        $areas->map('basalto.setup.navigation', 'navigation')
            ->map('basalto.setup.types', 'structure')
            ->map('basalto.setup.snippets', 'structure')
            ->map('basalto.setup.publish', 'publishing')
            ->map('basalto.setup.legal', 'publishing');
        // Verwaltung
        $areas->map('basalto.users.', 'users')
            ->map('basalto.invitations.', 'users')
            ->map('basalto.sites.sso', 'users')
            ->map('basalto.roles.', 'users')
            ->route('basalto.roles.store', 'users', Level::All)
            ->route('basalto.roles.update', 'users', Level::All)
            ->route('basalto.roles.reset', 'users', Level::All)
            ->route('basalto.roles.destroy', 'users', Level::All)
            ->map('basalto.settings.', 'settings')
            ->map('basalto.modules', 'settings')
            ->map('basalto.sites.', 'settings')
            ->map('basalto.license', 'license')
            ->map('basalto.operations', 'operations')
            // CR-007: prüfen heisst ansehen, einspielen verlangt «alles»
            ->map('basalto.updates', 'updates')
            ->route('basalto.updates.check', 'updates', Level::View)
            ->route('basalto.updates.core', 'updates', Level::All)
            ->route('basalto.updates.plugin', 'updates', Level::All)
            ->route('basalto.updates.theme', 'updates', Level::All)
            ->map('basalto.activity', 'activity')
            ->map('basalto.statistics', 'statistics')
            ->map('basalto.privacy.', 'privacy');
        // Für alle Angemeldeten: Übersicht, Profil, Passkeys, Suche, Hilfe, Rechtstexte, Wechsel der Site, Stand der Kopfzeile.
        // Der Konto-Bereich der SaaS prüft selbst nach der Rolle am Konto (Besitzer, Abrechnung, Admin), nicht nach der Site-Rolle
        $areas->open('basalto.saas.account.', 'basalto.dashboard', 'basalto.logout', 'basalto.profile', 'basalto.profile.', 'basalto.passkeys', 'basalto.passkeys.',
            'basalto.search', 'basalto.help', 'basalto.help.', 'basalto.legal', 'basalto.site.switch', 'basalto.status', 'basalto.support.open',
            // TB 27: Glocke und Center — jede Person sieht nur ihre eigenen Benachrichtigungen
            'basalto.notifications.',
            // TB 30: Hilfen der Block-Formulare (Linkziele, Vorschau) — für Seiten und Bausteine; der Controller prüft beides
            'basalto.editor.');
    }
}
