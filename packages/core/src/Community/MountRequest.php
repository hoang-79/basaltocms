<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\Site;
use Illuminate\Http\Request;

/**
 * Eine Anfrage an eine Einhängeseite: Site, Sprache, die Seite und ihre Adresse (`base`, mit Sprachpräfix, ohne
 * Schrägstrich am Ende) und der Rest des Pfads darunter (`rest`, ohne Schrägstriche am Rand, leer für die Seite selbst).
 */
final readonly class MountRequest
{
    /** @var list<string> */
    public array $segments;

    public function __construct(public Site $site, public string $locale, public Entry $entry, public EntryVersion $version, public string $base, public string $rest, public Request $request)
    {
        $this->segments = $rest === '' ? [] : array_map('rawurldecode', explode('/', $rest));
    }

    /**
     * Eine Adresse unter der Einhängeseite: `url('thema/12-titel')` → `/community/thema/12-titel`.
     *
     * @param  array<string, scalar|null>  $query
     */
    public function url(string $path = '', array $query = []): string
    {
        $path = trim($path, '/');
        $url = ($this->base === '' ? '' : $this->base).($path === '' ? ($this->base === '' ? '/' : '') : '/'.$path);
        $query = array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== '');

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    /** Ein Wert aus der Abfrage, gekürzt und getrimmt. */
    public function query(string $key): string
    {
        $value = $this->request->query($key);

        return is_string($value) ? trim(mb_substr($value, 0, 200)) : '';
    }

    /** Die Seitennummer aus `?seite=` (ab 1). */
    public function pageNumber(): int
    {
        return max(1, (int) $this->query('seite'));
    }
}
