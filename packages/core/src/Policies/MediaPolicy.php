<?php

declare(strict_types=1);

namespace Basalto\Core\Policies;

use Basalto\Core\Access\Access;
use Basalto\Core\Access\Level;
use Basalto\Core\Models\Media;
use Basalto\Core\Models\Site;
use Illuminate\Contracts\Auth\Authenticatable;

/** Mediathek (K-06, K-08): Mitglieder der Site sehen und laden hoch; Papierkorb, Ordner und Ersetzen brauchen «Medien verwalten». */
final class MediaPolicy
{
    public function view(Authenticatable $user, Media $media): bool
    {
        return app(Access::class)->allows($user, $media->site, 'media', Level::View);
    }

    public function upload(Authenticatable $user, Site $site): bool
    {
        return app(Access::class)->allows($user, $site, 'media', Level::Edit);
    }

    public function update(Authenticatable $user, Media $media): bool
    {
        return $this->upload($user, $media->site);
    }

    public function delete(Authenticatable $user, Media $media): bool
    {
        return $this->manage($user, $media->site);
    }

    /** Ordner, Ersetzen, Löschen — CR-005: «Medien löschen». */
    public function manage(Authenticatable $user, Site $site): bool
    {
        return app(Access::class)->allows($user, $site, 'media', Level::Delete);
    }
}
