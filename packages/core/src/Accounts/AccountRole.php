<?php

declare(strict_types=1);

namespace Basalto\Core\Accounts;

/** Rollen auf Kontoebene (ADR-008): Besitzer, Abrechnung, Verwaltung — getrennt von den Site-Rollen. */
enum AccountRole: string
{
    case Owner = 'owner';
    case Billing = 'billing';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Owner => __('basalto::roles.account_owner'),
            self::Billing => __('basalto::roles.account_billing'),
            self::Admin => __('basalto::roles.account_admin'),
        };
    }
}
