<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use Basalto\Core\Notifications\Notify;
use Illuminate\Support\Collection;

/**
 * Pinnwand im Profil (TB 52, CR-009 Nr. 19): Mitglieder schreiben einander auf das Profil — öffentlich wie das Profil
 * selbst. Wer sein Profil hat, bestimmt, wer schreiben darf: alle Mitglieder (Vorgabe), nur Personen, denen er folgt,
 * oder niemand. Blockieren gilt in beide Richtungen, eine Schreibsperre auch hier. Löschen dürfen, wer geschrieben hat,
 * wer das Profil hat, und Team und Moderation. Die Person bekommt eine Benachrichtigung; Einträge lassen sich melden.
 */
final class Wall
{
    public const PREFERENCE = 'community.wall';

    public const MODES = ['alle', 'gefolgte', 'niemand'];

    public const NOTIFICATION = 'community.wall';

    public const REPORT_TYPE = 'community_wall_post';

    public function __construct(private readonly Relations $relations, private readonly Profiles $profiles) {}

    public function mode(Customer $owner): string
    {
        $mode = data_get($owner->preferences, self::PREFERENCE, 'alle');

        return in_array($mode, self::MODES, true) ? (string) $mode : 'alle';
    }

    public function canWrite(Site $site, ?Customer $author, Customer $owner): bool
    {
        if ($author === null || $this->profiles->of($author) === null || app(Sanctions::class)->isMuted($author)) {
            return false;
        }
        if ($author->id === $owner->id) {
            return true;
        }
        if ($this->relations->blocks($owner->id, $author->id) || $this->relations->blocks($author->id, $owner->id)) {
            return false;
        }

        return match ($this->mode($owner)) {
            'niemand' => false,
            'gefolgte' => $this->relations->follows($owner, $author->id),
            default => true,
        };
    }

    public function canDelete(Site $site, ?Customer $by, CommunityWallPost $post, Customer $owner): bool
    {
        if ($by === null) {
            return false;
        }

        return $by->id === $post->customer_id || $by->id === $owner->id || app(VisitorGroups::class)->of($site, $by)->contains(
            static fn (VisitorGroup $g): bool => in_array($g->key, [VisitorGroup::TEAM, VisitorGroup::MODERATORS], true));
    }

    /** @param  array<string, mixed>  $body */
    public function post(Site $site, Customer $author, CommunityProfile $owner, array $body): CommunityWallPost
    {
        $post = CommunityWallPost::query()->create(['site_id' => $site->id, 'profile_id' => $owner->id, 'customer_id' => $author->id, 'body' => $body]);
        $ownerCustomer = Customer::query()->withoutGlobalScopes()->find($owner->customer_id);
        if ($ownerCustomer !== null && $ownerCustomer->id !== $author->id) {
            Notify::make(self::NOTIFICATION)->site($site)->title(':name hat auf Ihre Pinnwand geschrieben', ['name' => $this->profiles->displayName($author)])
                ->url($this->url($site, $owner, $post), 'Pinnwand öffnen')->to($ownerCustomer)->send();
        }

        return $post;
    }

    /** @return Collection<int, CommunityWallPost> */
    public function entries(CommunityProfile $owner, int $limit = 30): Collection
    {
        return CommunityWallPost::query()->withoutGlobalScopes()->where('profile_id', $owner->id)->orderByDesc('id')->limit($limit)->get();
    }

    public function count(CommunityProfile $owner): int
    {
        return CommunityWallPost::query()->withoutGlobalScopes()->where('profile_id', $owner->id)->count();
    }

    public function url(Site $site, CommunityProfile $owner, ?CommunityWallPost $post = null): ?string
    {
        $base = app(Mounts::class)->baseFor($site);

        return $base === null ? null : $base.'/mitglied/'.$owner->pathSegment().'?reiter=pinnwand'.($post === null ? '' : '#pinnwand-'.$post->id);
    }
}
