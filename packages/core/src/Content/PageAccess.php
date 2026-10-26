<?php

declare(strict_types=1);

namespace Basalto\Core\Content;

use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\FrontendAccounts\LoginForm;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Geschützte Seiten (TB 13 Scheibe 2, M-23): «Nur für Mitglieder» im Reiter «Zugriff», vererbt auf Unterseiten
 * (`Pages::isProtected`). Sehen dürfen sie, wen das Modul der Mitglieder zulässt (`ProtectedPageGate`), und die
 * Backend-Redaktion der Site (Vorschau, Frontend-Editor). Alles Öffentliche fragt hier: Navigation und Listen (für
 * Gäste ohne geschützte Seiten), Sitemap, Suchindex und Feeds (nie mit geschützten Seiten — sie sind für alle gleich).
 *
 * Das Urteil «darf sehen» gilt je Anfrage und Site (in den Attributen der Anfrage, nicht in einem Singleton — Octane).
 */
final class PageAccess
{
    /** Die Klasse des Moduls, das über geschützte Seiten entscheidet (Konfiguration statt statischer Eigenschaft). */
    public const GATE = 'basalto.page_access.gate';

    public function __construct(private readonly Pages $pages) {}

    /** @param  class-string<ProtectedPageGate>  $gate */
    public static function useGate(string $gate): void
    {
        config()->set(self::GATE, $gate);
    }

    public function isProtected(Entry $entry): bool
    {
        return $this->pages->isProtected($entry);
    }

    /** Darf, wer gerade fragt, diesen Eintrag sehen? Öffentliche immer, geschützte nur Mitglieder und die Redaktion. */
    public function mayView(Entry $entry, ?Request $request = null): bool
    {
        return ! $this->pages->isProtected($entry) || $this->viewerAllowed($entry->site, $request);
    }

    public function viewerAllowed(Site $site, ?Request $request = null): bool
    {
        $request ??= request();
        $key = 'basalto.page_access.'.$site->id;
        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, $this->decide($site, $request));
        }

        return (bool) $request->attributes->get($key);
    }

    /**
     * Für den Reiter «Zugriff»: Gibt es ein Modul für Mitglieder, ist es auf der Site an, und wo werden sie verwaltet?
     *
     * @return array{installed: bool, enabled: bool, admin_url: string|null}
     */
    public function module(Site $site): array
    {
        $gate = $this->gate();

        return ['installed' => $gate !== null, 'enabled' => $gate?->enabled($site) ?? false, 'admin_url' => $gate?->adminUrl()];
    }

    /** Status der Antwort für Gäste (401) und Angemeldete ohne Zugang (403). */
    public function deniedStatus(): int
    {
        return CustomerAuth::current() === null ? 401 : 403;
    }

    /** Was statt des Inhalts erscheint: vom Modul, sonst ein Hinweis ohne Anmeldung. */
    public function deniedMarkup(Site $site, Request $request, string $locale, string $path): string
    {
        $gate = $this->gate();
        if ($gate !== null) {
            return $gate->denied($site, $request, $locale, $path);
        }

        return sprintf('<p class="account-intro">%s</p>', Html::e(__('Diese Seite ist nur für Mitglieder.', [], $locale)));
    }

    /** Die Anmeldeseite (`GET /account/login`): vom Modul der Mitglieder, wenn es an ist — sonst die Anmeldung des Kontos. */
    public function loginMarkup(Site $site, Request $request, string $locale, string $path): string
    {
        $gate = $this->gate();
        if ($gate !== null && $gate->enabled($site)) {
            return $gate->login($site, $request, $locale, $path);
        }
        $customer = CustomerAuth::current();
        $form = app(LoginForm::class);

        return $customer === null ? $form->render($request, $locale, $path) : $form->signedIn($customer, $locale, $path);
    }

    private function decide(Site $site, Request $request): bool
    {
        // Die Backend-Redaktion der Site (Gate «view» der Site) sieht geschützte Seiten — ihre Antworten umgehen den Seiten-Cache
        $user = $request->user();
        if ($user !== null && Gate::forUser($user)->allows('view', $site)) {
            return true;
        }

        return $this->gate()?->allows($site, $request) ?? false;
    }

    private function gate(): ?ProtectedPageGate
    {
        $class = config(self::GATE);
        if (! is_string($class) || ! is_a($class, ProtectedPageGate::class, true)) {
            return null;
        }

        return app($class);
    }
}
