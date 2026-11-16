<?php

declare(strict_types=1);

namespace Basalto\Core\Choices;

use Basalto\Core\Backend\Backend;
use Basalto\Core\Models\Site;

/**
 * Das Verzeichnis der Auswahllisten (CR-022): Kern und Plugins melden ihre Listen hier an; die Seite
 * Verwaltung › Auswahllisten und die Felder `FormField::choice()` und `->suggest()` lesen sie von hier.
 */
final class ChoiceLists
{
    /** @var array<string, ChoiceList> */
    private array $lists = [];

    /** Anmelden — gibt es die Liste schon (von einem anderen Plugin geteilt), gilt die bestehende. */
    public function register(ChoiceList $list): ChoiceList
    {
        return $this->lists[$list->key] ??= $list;
    }

    public function get(string $key): ?ChoiceList
    {
        return $this->lists[$key] ?? null;
    }

    /**
     * Listen, die für die Site gelten — mindestens eines ihrer Module ist an (ohne Module immer).
     *
     * @return list<ChoiceList>
     */
    public function forSite(Site $site): array
    {
        $backend = app(Backend::class);

        return array_values(array_filter($this->lists, static fn (ChoiceList $list): bool => $list->modules() === []
            || array_filter($list->modules(), static fn (string $module): bool => $backend->moduleEnabled($module, $site)) !== []));
    }
}
