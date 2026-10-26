<?php

declare(strict_types=1);

namespace Basalto\Core\Content;

use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Theme\ThemeException;
use Basalto\Core\Theme\ThemeLoader;

/**
 * Vollständigkeit einer Fassung (Autosave): Beim Speichern darf ein Entwurf unvollständig sein; erst das Einreichen
 * verlangt alle Pflichtfelder des Themes — im Editor, in der Massenaktion, überall dieselbe Prüfung.
 */
final class Completeness
{
    public function __construct(private readonly ThemeLoader $themes) {}

    /**
     * Leere Pflichtfelder mit Pfad wie im Editor («content.0.title», «fields.kunde») und Meldung.
     *
     * @return array<string, string>
     */
    public function missing(Entry $entry, EntryVersion $version): array
    {
        try {
            $schema = ThemeSchema::fromTheme($this->themes->forSite($entry->site));
        } catch (ThemeException) {
            return [];
        }
        $content = is_array($version->data['content'] ?? null) ? array_values($version->data['content']) : [];

        return $schema->validate($entry->type, $content) + $schema->validateTypeFields($entry->type, $version->data);
    }
}
