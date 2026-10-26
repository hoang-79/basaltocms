<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

/**
 * Was eine Einhängeseite antwortet: Markup mit Titel, weiteren Brotkrumen und Metadaten — oder eine Weiterleitung oder
 * «nicht gefunden». `personal` hält die Antwort aus jedem Cache (Formulare mit CSRF-Token, Ansichten für das Konto).
 */
final readonly class MountResult
{
    /**
     * @param  list<array{label: string, href: string}>  $crumbs  Brotkrumen unter der Einhängeseite, die letzte ist die aktuelle
     */
    private function __construct(public string $kind, public string $html = '', public ?string $title = null, public array $crumbs = [], public ?string $description = null,
        public bool $personal = false, public bool $noindex = false, public int $status = 200, public ?string $location = null, public string $head = '', public ?string $image = null) {}

    /**
     * `image` (TB 51): volle Adresse des Vorschaubilds beim Teilen (`og:image`) — sonst das der Einhängeseite.
     *
     * @param  list<array{label: string, href: string}>  $crumbs
     */
    public static function page(string $html, ?string $title = null, array $crumbs = [], ?string $description = null, bool $personal = false, bool $noindex = false, int $status = 200, string $head = '', ?string $image = null): self
    {
        return new self('page', $html, $title, $crumbs, $description, $personal, $noindex, $status, null, $head, $image);
    }

    /** TB 41: eine Antwort ohne Layout (etwa ein RSS-Feed) mit Inhaltstyp; Gäste-Antworten dürfen in den Cache. */
    public static function raw(string $body, string $contentType): self
    {
        return new self('raw', $body, head: $contentType);
    }

    public static function redirect(string $location): self
    {
        return new self('redirect', location: $location);
    }

    public static function notFound(): self
    {
        return new self('not_found');
    }

    /** Eine Fehlerseite der Einhängeseite (z. B. 403 «keine Berechtigung») mit Markup im Layout. */
    public static function denied(string $html, string $title): self
    {
        return new self('page', $html, $title, [], null, true, true, 403);
    }
}
