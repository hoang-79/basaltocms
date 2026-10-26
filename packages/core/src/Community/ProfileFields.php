<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use Basalto\Core\Settings\Settings;

/**
 * Eigene Profilfelder (CR-006 FO-18): im Extranet festgelegt (Schlüssel, Beschriftung, Art, Sichtbarkeit, Pflicht), im
 * Profilformular ausgefüllt, im Profil gezeigt — sichtbar für alle, nur für angemeldete Mitglieder oder nur fürs Team.
 *
 * TB 52 (CR-009 Nr. 17): dazu die Art «Auswahl» mit festen Optionen (höchstens 30, je bis 60 Zeichen).
 *
 * @phpstan-type Field array{key: string, label: string, type: string, visibility: string, required: bool, options: list<string>}
 */
final class ProfileFields
{
    public const TYPES = ['text', 'textarea', 'url', 'select'];

    public const VISIBILITY = ['all', 'members', 'team'];

    public function __construct(private readonly Settings $settings) {}

    /** @return list<Field> */
    public function definitions(Site $site): array
    {
        $out = [];
        foreach ((array) $this->settings->get('community.profile_fields', [], $site) as $field) {
            if (is_array($field) && is_string($field['key'] ?? null) && is_string($field['label'] ?? null)) {
                $options = self::options($field['options'] ?? []);
                $type = in_array($field['type'] ?? null, self::TYPES, true) ? (string) $field['type'] : 'text';
                $out[] = ['key' => $field['key'], 'label' => $field['label'], 'type' => $type === 'select' && $options === [] ? 'text' : $type,
                    'visibility' => in_array($field['visibility'] ?? null, self::VISIBILITY, true) ? (string) $field['visibility'] : 'all', 'required' => (bool) ($field['required'] ?? false),
                    'options' => $type === 'select' ? $options : []];
            }
        }

        return $out;
    }

    /**
     * Optionen einer Auswahl: aus einer Liste oder aus Text (eine je Zeile), ohne Leeres und Doppeltes.
     *
     * @return list<string>
     */
    public static function options(mixed $value): array
    {
        $items = is_array($value) ? $value : preg_split('/\R/', (string) $value);
        $out = [];
        foreach ($items ?: [] as $item) {
            $item = mb_substr(trim(strip_tags((string) $item)), 0, 60);
            if ($item !== '' && ! in_array($item, $out, true) && count($out) < 30) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** @param  list<Field>  $fields */
    public function save(Site $site, array $fields): void
    {
        $this->settings->set('community.profile_fields', $fields, $site);
    }

    /**
     * Darf `$viewer` das Feld im Profil von `$owner` sehen? Die Person selbst sieht immer alles.
     *
     * @param  Field  $field
     */
    public function visible(Site $site, array $field, ?Customer $viewer, Customer $owner): bool
    {
        return match ($field['visibility']) {
            'members' => $viewer !== null,
            'team' => $viewer !== null && ($viewer->id === $owner->id || app(VisitorGroups::class)->of($site, $viewer)->contains(
                static fn (VisitorGroup $g): bool => in_array($g->key, [VisitorGroup::TEAM, VisitorGroup::MODERATORS], true))),
            default => true,
        } || ($viewer !== null && $viewer->id === $owner->id);
    }
}
