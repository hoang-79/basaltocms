<?php

declare(strict_types=1);

namespace Basalto\Core\Policies;

use Basalto\Core\Access\Access;
use Basalto\Core\Access\Level;
use Basalto\Core\Models\Entry;
use Illuminate\Contracts\Auth\Authenticatable;

/** Rechte je Site (K-02, K-06) — seit CR-005 nach Bereich und Stufe der Rolle (Seiten, Übersetzung, Freigabe). */
final class EntryPolicy
{
    public function view(Authenticatable $user, Entry $entry): bool
    {
        return $this->allows($user, $entry, 'pages', Level::View) || $this->allows($user, $entry, 'translations', Level::View) || $this->allows($user, $entry, 'approval', Level::View);
    }

    /** Bearbeiten — die Sprache prüft der Controller (`Access::mayEditLocale`, CR-005 R-05). */
    public function update(Authenticatable $user, Entry $entry): bool
    {
        return $this->allows($user, $entry, 'pages', Level::Edit) || $this->allows($user, $entry, 'translations', Level::Edit);
    }

    /**
     * Auf der Seite bearbeiten (Frontend-Editor, M-19): wer Seiten bearbeiten darf — Übersetzen allein genügt nicht, denn
     * Übersetzende arbeiten Feld für Feld gegen die Quellfassung (M-06), nicht frei auf der Seite.
     */
    public function editOnPage(Authenticatable $user, Entry $entry): bool
    {
        return $this->allows($user, $entry, 'pages', Level::Edit) && app(Access::class)->mayEditLocale($user, $entry->site, $entry->site->default_locale);
    }

    /** Anlegen verlangt «Seiten bearbeiten» und die Quellsprache — Struktur gehört nicht der Übersetzung. */
    public function create(Authenticatable $user, Entry $entry): bool
    {
        return $this->allows($user, $entry, 'pages', Level::Edit) && app(Access::class)->mayEditLocale($user, $entry->site, $entry->site->default_locale);
    }

    public function delete(Authenticatable $user, Entry $entry): bool
    {
        return $this->allows($user, $entry, 'pages', Level::Delete) && app(Access::class)->mayEditLocale($user, $entry->site, $entry->site->default_locale);
    }

    public function approve(Authenticatable $user, Entry $entry): bool
    {
        return $this->allows($user, $entry, 'approval', Level::Edit);
    }

    public function publish(Authenticatable $user, Entry $entry): bool
    {
        return $this->allows($user, $entry, 'approval', Level::Edit);
    }

    private function allows(Authenticatable $user, Entry $entry, string $area, Level $level): bool
    {
        return app(Access::class)->allows($user, $entry->site, $area, $level);
    }
}
