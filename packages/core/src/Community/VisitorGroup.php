<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Eine Besuchergruppe (CR-006 FO-K3). Gruppen mit `key` gehören dem System und lassen sich nicht löschen: Gäste
 * (nicht angemeldet), Alle (Gäste und Konten), Mitglieder (jedes Konto), Moderation, Team (mit dem Extranet
 * verknüpfte Konten). Eigene Gruppen haben keinen Schlüssel; ihre Mitglieder stehen in `visitor_group_customer`.
 *
 * @property int $id
 * @property int $site_id
 * @property string|null $key
 * @property string $name
 * @property string|null $badge
 * @property int $position
 */
final class VisitorGroup extends Model
{
    use BelongsToSite;

    public const GUESTS = 'guests';

    public const EVERYONE = 'everyone';

    public const MEMBERS = 'members';

    public const MODERATORS = 'moderators';

    public const TEAM = 'team';

    /** Gruppen, deren Mitgliedschaft sich aus dem Konto ergibt — nicht zuweisbar. */
    public const IMPLICIT = [self::GUESTS, self::EVERYONE, self::MEMBERS, self::TEAM];

    protected $table = 'visitor_groups';

    protected $fillable = ['site_id', 'key', 'name', 'badge', 'position'];

    /** @return BelongsToMany<Customer, $this> */
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'visitor_group_customer')->withPivot('created_at');
    }

    public function isSystem(): bool
    {
        return $this->key !== null;
    }

    public function isAssignable(): bool
    {
        return ! in_array($this->key, self::IMPLICIT, true);
    }
}
