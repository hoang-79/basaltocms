<?php

declare(strict_types=1);

namespace Basalto\Core\Access;

/**
 * Eine Rolle, wie sie gilt: Stufe je Bereich und erlaubte Sprachen — aus der Vorlage oder aus der Tabelle `roles`.
 * `*` steht für alle Bereiche (Betreiber, Administration).
 */
final readonly class RoleDefinition
{
    /**
     * @param  array<string, int>  $levels
     * @param  array{mode: string, codes?: list<string>}|null  $languages
     */
    public function __construct(
        public string $key,
        public string $name,
        public array $levels,
        public ?array $languages,
        public bool $locked = false,
    ) {}

    public function level(string $area): Level
    {
        return Level::tryFrom((int) ($this->levels[$area] ?? $this->levels['*'] ?? 0)) ?? Level::None;
    }

    /**
     * Sprachen, in denen die Rolle Inhalte bearbeiten darf.
     *
     * @param  list<string>  $siteLocales
     * @return list<string>
     */
    public function locales(array $siteLocales, string $defaultLocale): array
    {
        return match ($this->languages['mode'] ?? null) {
            'except_default' => array_values(array_diff($siteLocales, [$defaultLocale])),
            'only' => array_values(array_intersect($siteLocales, $this->languages['codes'] ?? [])),
            default => $siteLocales,
        };
    }

    /** Ob die Rolle alles darf (Betreiber, Administration ohne Einschränkung). */
    public function isUnrestricted(): bool
    {
        return ($this->levels['*'] ?? 0) === Level::All->value && $this->languages === null;
    }
}
