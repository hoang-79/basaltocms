<?php

declare(strict_types=1);

namespace Basalto\Core\Content;

/**
 * Die Seitentypen des Seitenbaums (M-01, Contao-Vorbild): Seite, Weiterleitung, Fehlerseiten, Feed, Ordner.
 * Alles andere in `entries.type` ist ein Inhaltstyp aus Lava («project», «person») ohne Platz im Baum.
 */
enum PageType: string
{
    case Page = 'page';
    case Redirect = 'redirect';
    case Error401 = 'error_401';
    case Error403 = 'error_403';
    case Error404 = 'error_404';
    case Error503 = 'error_503';
    case Feed = 'feed';
    case Folder = 'folder';

    /** Die zwölf Reiter der Seiteneinstellungen in ihrer Reihenfolge (Mockup 28). */
    public const TABS = ['name', 'routing', 'meta', 'image', 'canonical', 'access', 'layout', 'cache', 'rights', 'expert', 'keyboard', 'publication'];

    public static function isPageType(string $type): bool
    {
        return self::tryFrom($type) !== null;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Page => __('Seite'),
            self::Redirect => __('Weiterleitung'),
            self::Error401 => __('Fehlerseite :code', ['code' => 401]),
            self::Error403 => __('Fehlerseite :code', ['code' => 403]),
            self::Error404 => __('Fehlerseite :code', ['code' => 404]),
            self::Error503 => __('Fehlerseite :code', ['code' => 503]),
            self::Feed => __('Feed'),
            self::Folder => __('Ordner'),
        };
    }

    /**
     * Der Typ bestimmt, welche Reiter es gibt: eine Weiterleitung vier, eine Seite zwölf, ein Ordner drei (TB 13 Scheibe 2:
     * «Zugriff», damit ein Ordner einen Mitgliederbereich schützen kann).
     *
     * @return list<string>
     */
    public function tabs(): array
    {
        return match ($this) {
            self::Page => self::TABS,
            self::Redirect => ['name', 'routing', 'rights', 'publication'],
            self::Feed => ['name', 'routing', 'meta', 'cache', 'rights', 'publication'],
            self::Folder => ['name', 'access', 'rights'],
            self::Error401, self::Error403, self::Error404, self::Error503 => ['name', 'meta', 'layout', 'rights', 'publication'],
        };
    }

    public function hasTab(string $tab): bool
    {
        return in_array($tab, $this->tabs(), true);
    }

    /** Trägt der Typ ein Pfadsegment bei? Ordner sind nur Struktur, Fehlerseiten antworten auf einen Code. */
    public function hasSlug(): bool
    {
        return in_array($this, [self::Page, self::Redirect, self::Feed], true);
    }

    /** Nur Seiten tragen Blöcke; die anderen Typen haben Einstellungen, aber keinen Inhalt. */
    public function hasContent(): bool
    {
        return $this === self::Page;
    }

    public function isError(): bool
    {
        return $this->errorCode() !== null;
    }

    public function errorCode(): ?int
    {
        return match ($this) {
            self::Error401 => 401,
            self::Error403 => 403,
            self::Error404 => 404,
            self::Error503 => 503,
            default => null,
        };
    }

    public static function forErrorCode(int $code): ?self
    {
        return self::tryFrom('error_'.$code);
    }
}
