<?php

declare(strict_types=1);

namespace Basalto\Core\Sites;

/** Berechtigungen (K-06) — geprüft in Policies, nie in Vorlagen. */
enum Permission: string
{
    case ManageSite = 'site.manage';
    case ManageUsers = 'users.manage';
    case EditContent = 'content.edit';
    case TranslateContent = 'content.translate';
    case ApproveContent = 'content.approve';
    case PublishContent = 'content.publish';
    case ManageMedia = 'media.manage';
}
