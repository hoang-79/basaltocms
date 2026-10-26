<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\Models\Site;
use Basalto\Core\Settings\Settings;

/**
 * Einstellungen der Moderation einer Site (CR-006 FO-17) mit ihren Vorgaben — Flood-Control, Schwelle der Meldungen,
 * Verwarnungen und ihre Folgen, Zensur. Gepflegt im Extranet unter Community › Einstellungen.
 *
 * @phpstan-type Values array{flood_seconds: int, auto_hide: int, warning_days: int, mute_points: int, mute_days: int, ban_points: int, ban_days: int, censor: list<string>}
 */
final class ModerationSettings
{
    /** @var array<string, array{0: string, 1: int|list<string>}> Feld → Schlüssel und Vorgabe */
    public const KEYS = [
        'flood_seconds' => ['community.flood_seconds', 15],
        'auto_hide' => ['community.reports.auto_hide', 3],
        'warning_days' => ['community.warnings.days', 90],
        'mute_points' => ['community.warnings.mute_points', 5],
        'mute_days' => ['community.warnings.mute_days', 7],
        'ban_points' => ['community.warnings.ban_points', 10],
        'ban_days' => ['community.warnings.ban_days', 30],
        'censor' => ['community.censor', []],
    ];

    public function __construct(private readonly Settings $settings) {}

    /** @return Values */
    public function all(Site $site): array
    {
        $out = [];
        foreach (self::KEYS as $field => [$key, $default]) {
            $value = $this->settings->get($key, $default, $site);
            $out[$field] = is_array($default) ? array_values(array_filter(array_map('strval', is_array($value) ? $value : []), static fn (string $w): bool => trim($w) !== '')) : max(0, (int) $value);
        }

        /** @var Values $out */
        return $out;
    }

    /** @param  array<string, mixed>  $values */
    public function save(Site $site, array $values): void
    {
        foreach (self::KEYS as $field => [$key, $default]) {
            if (array_key_exists($field, $values)) {
                $this->settings->set($key, is_array($default) ? array_values((array) $values[$field]) : max(0, (int) $values[$field]), $site);
            }
        }
    }

    public function int(Site $site, string $field): int
    {
        $value = $this->all($site)[$field] ?? 0;

        return is_int($value) ? $value : 0;
    }
}
