<?php

declare(strict_types=1);

namespace Basalto\Modules\Members;

use Basalto\Core\Content\ProtectedPageGate;
use Basalto\Core\Models\Site;
use Basalto\Modules\Members\Frontend\MemberMarkup;
use Illuminate\Http\Request;

/** Geschützte Seiten (M-23): sehen darf sie, wer auf dieser Site aktives Mitglied ist — und nur, solange das Modul an ist. */
final class MembersGate implements ProtectedPageGate
{
    public function __construct(private readonly Members $members) {}

    public function enabled(Site $site): bool
    {
        return $this->members->enabled($site);
    }

    public function adminUrl(): string
    {
        return route('basalto.members.index');
    }

    public function allows(Site $site, Request $request): bool
    {
        return $this->members->enabled($site) && ($this->members->current($site)?->isActive() ?? false);
    }

    public function denied(Site $site, Request $request, string $locale, string $path): string
    {
        return app(MemberMarkup::class)->panel($site, $request, $locale, $path, true);
    }

    public function login(Site $site, Request $request, string $locale, string $path): string
    {
        return app(MemberMarkup::class)->panel($site, $request, $locale, $path, false);
    }
}
