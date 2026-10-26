<?php

declare(strict_types=1);

namespace Basalto\Core\Sites;

/** Systemrollen je Site (K-06): Betreiber, Administrator, Redaktion, Freigabe, Übersetzung — eigene Rollen folgen als Daten. */
enum SiteRole: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
    case Editor = 'editor';
    case Approver = 'approver';
    case Translator = 'translator';

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner, self::Administrator => Permission::cases(),
            self::Editor => [Permission::EditContent, Permission::ManageMedia],
            self::Approver => [Permission::ApproveContent, Permission::PublishContent],
            self::Translator => [Permission::TranslateContent],
        };
    }

    public function can(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => __('basalto::roles.owner'),
            self::Administrator => __('basalto::roles.administrator'),
            self::Editor => __('basalto::roles.editor'),
            self::Approver => __('basalto::roles.approver'),
            self::Translator => __('basalto::roles.translator'),
        };
    }
}
