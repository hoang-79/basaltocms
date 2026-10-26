<?php

declare(strict_types=1);

namespace Basalto\Core\Theme;

use Basalto\Core\Models\Site;
use Basalto\Lava\Theme\Line;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Die rote Linie A–Z einer Site für Kopfzeile und Einrichtung — derselbe Stand wie `basalto:check`, je Anfrage einmal
 * kompiliert (1,5 ms im Spike); ein Theme mit Fehlern liefert die Linie mit «✗», ein fehlendes Theme eine leere Linie.
 */
final class ThemeStatus
{
    /** @var array<int, array{line: list<array{letter: string, keyword: string, status: string, summary: string}>, theme: array{name: string, source: string, checked_at: string}|null}> */
    private array $cache = [];

    public function __construct(private readonly ThemeLoader $loader) {}

    /** @return array{line: list<array{letter: string, keyword: string, status: string, summary: string}>, theme: array{name: string, source: string, checked_at: string}|null} */
    public function forSite(?Site $site): array
    {
        if ($site === null) {
            return ['line' => [], 'theme' => null];
        }
        if (isset($this->cache[$site->id])) {
            return $this->cache[$site->id];
        }
        try {
            $resolved = $this->loader->sourceFor($site);
            $result = $this->loader->compileFor($site);
            $line = (new Line($result['theme'], $result['diagnostics']))->rows();
            $info = ['name' => $resolved['name'], 'source' => $resolved['kind'], 'checked_at' => CarbonImmutable::now()->toIso8601String()];
        } catch (Throwable) {
            $line = [];
            $info = null;
        }

        return $this->cache[$site->id] = ['line' => $line, 'theme' => $info];
    }
}
