<?php

declare(strict_types=1);

namespace Basalto\Core\Access;

use Basalto\Core\Sites\SiteRole;
use Basalto\Core\Support\Text;

/**
 * Vorlagen der Rollen (CR-005): die fünf bisherigen Rollen als Ausgangsstand. Solange der Betreiber eine Vorlage nicht
 * anpasst, gilt sie aus dem Code; angepasst steht sie in der Tabelle `roles`. «Betreiber» ist unveränderlich (R-10).
 * Bereiche, die hier fehlen, haben «keine».
 */
final class RoleTemplates
{
    public const OWNER = 'owner';

    public const ADMINISTRATOR = 'administrator';

    public const EDITOR = 'editor';

    public const APPROVER = 'approver';

    public const TRANSLATOR = 'translator';

    public const KEYS = [self::OWNER, self::ADMINISTRATOR, self::EDITOR, self::APPROVER, self::TRANSLATOR];

    /** TB 19 (R-12): Vorlagen für das Team im Hersteller-Extranet — nicht für Sites wählbar */
    public const STAFF = 'staff';

    public const ACCOUNTING = 'accounting';

    public const EXTRANET_KEYS = [self::STAFF, self::ACCOUNTING];

    /** Reservierte Schlüssel, die keine eigene Rolle tragen darf (Support-Zugriff, P-16). */
    public const RESERVED = ['support'];

    public static function isTemplate(string $key): bool
    {
        return in_array($key, self::KEYS, true) || in_array($key, self::EXTRANET_KEYS, true);
    }

    /** Sprachen: null = alle; «except_default» = alle ausser der Quellsprache der Site. */
    public const ALL_LANGUAGES = null;

    /** Die übersetzte Bezeichnung einer Vorlage (Sprache der Oberfläche). */
    public static function label(string $key): ?string
    {
        return match ($key) {
            self::STAFF => Text::t('Team (Extranet)'),
            self::ACCOUNTING => Text::t('Buchhaltung (Extranet)'),
            default => SiteRole::tryFrom($key)?->label(),
        };
    }

    /** @return array{name: string, levels: array<string, int>, languages: array{mode: string, codes?: list<string>}|null, locked: bool}|null */
    public static function get(string $key): ?array
    {
        return match ($key) {
            self::OWNER => ['name' => 'Betreiber', 'levels' => ['*' => Level::All->value], 'languages' => null, 'locked' => true],
            self::ADMINISTRATOR => ['name' => 'Administration', 'levels' => ['*' => Level::All->value], 'languages' => null, 'locked' => false],
            self::EDITOR => ['name' => 'Redaktion', 'levels' => ['pages' => Level::Delete->value, 'media' => Level::Delete->value, 'translations' => Level::Edit->value,
                'forms' => Level::Delete->value, 'comments' => Level::Edit->value, 'members' => Level::Edit->value, 'ai' => Level::Edit->value], 'languages' => null, 'locked' => false],
            self::APPROVER => ['name' => 'Freigabe', 'levels' => ['pages' => Level::View->value, 'media' => Level::View->value, 'translations' => Level::View->value,
                'approval' => Level::All->value, 'forms' => Level::View->value, 'comments' => Level::View->value], 'languages' => null, 'locked' => false],
            self::TRANSLATOR => ['name' => 'Übersetzung', 'levels' => ['pages' => Level::Edit->value, 'media' => Level::View->value, 'translations' => Level::Edit->value,
                'ai' => Level::Edit->value], 'languages' => ['mode' => 'except_default'], 'locked' => false],
            self::STAFF => ['name' => 'Team (Extranet)', 'levels' => ['extranet_statistics' => Level::View->value, 'extranet_customers' => Level::Edit->value, 'extranet_invoices' => Level::View->value, 'licensing' => Level::View->value,
                'marketplace' => Level::Edit->value, 'extranet_saas' => Level::View->value, 'extranet_support' => Level::Edit->value, 'extranet_team' => Level::View->value], 'languages' => null, 'locked' => false],
            self::ACCOUNTING => ['name' => 'Buchhaltung (Extranet)', 'levels' => ['extranet_statistics' => Level::View->value, 'extranet_customers' => Level::Edit->value, 'extranet_invoices' => Level::All->value, 'licensing' => Level::Edit->value,
                'marketplace' => Level::View->value, 'extranet_saas' => Level::View->value], 'languages' => null, 'locked' => false],
            default => null,
        };
    }
}
