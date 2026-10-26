<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use Closure;

/**
 * Reiter im öffentlichen Profil (CR-006 FO-18): Module melden an, was sie über eine Person zeigen — das Forum ihre
 * Beiträge und Themen, der Marktplatz ihre Einträge und Bewertungen. Ein Reiter ohne Inhalt (Zahl 0) bleibt weg.
 * Beim Booten gefüllt (Singleton), danach nur gelesen.
 *
 * @phpstan-type Tab array{label: string, count: Closure(Site, Customer): int, render: Closure(Site, Customer, ?Customer, string, string): string}
 */
final class ProfileTabs
{
    /** @var array<string, Tab> */
    private array $tabs = [];

    /**
     * @param  Closure(Site, Customer): int  $count
     * @param  Closure(Site, Customer, ?Customer, string, string): string  $render  Site, Person, wer schaut, Sprache, Adresse der Community
     */
    public function register(string $key, string $label, Closure $count, Closure $render): self
    {
        $this->tabs[$key] = ['label' => $label, 'count' => $count, 'render' => $render];

        return $this;
    }

    /** @return array<string, Tab> */
    public function all(): array
    {
        return $this->tabs;
    }
}
