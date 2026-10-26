<?php

declare(strict_types=1);

namespace Basalto\Modules\Members;

/** Der Stand einer Mitgliedschaft (M-23): nur «aktiv» öffnet geschützte Seiten. */
enum MemberStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Pending => (string) __('Wartet auf Freigabe'),
            self::Active => (string) __('Aktiv'),
            self::Blocked => (string) __('Gesperrt'),
        };
    }
}
