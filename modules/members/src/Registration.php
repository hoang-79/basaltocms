<?php

declare(strict_types=1);

namespace Basalto\Modules\Members;

/**
 * Wie man Mitglied wird (je Site, Einstellung `members.registration`): offen — wer sich mit Code anmeldet, ist sofort
 * Mitglied; mit Freigabe — die Mitgliedschaft wartet, bis die Redaktion sie freigibt (Vorgabe); nur auf Einladung —
 * nur wen die Redaktion einlädt, bekommt überhaupt einen Code.
 */
enum Registration: string
{
    case Open = 'open';
    case Approval = 'approval';
    case Invite = 'invite';

    public const SETTING = 'members.registration';

    public function label(): string
    {
        return match ($this) {
            self::Open => (string) __('Offen'),
            self::Approval => (string) __('Mit Freigabe durch die Redaktion'),
            self::Invite => (string) __('Nur auf Einladung'),
        };
    }
}
