<?php

declare(strict_types=1);

namespace Basalto\Core\Seo;

use Basalto\Core\Models\Media;
use Basalto\Core\Models\Site;
use Basalto\Core\Theme\ThemeAssets;
use DateTimeZone;
use Throwable;

/**
 * Der Kopf einer Seite für Suchmaschinen und Netzwerke (M-10, CR-019 TB 106) — zusammengesetzt, wenn die Seite fertig
 * gerendert ist: `{% seo %}` hinterlässt {@see self::MARK}, die hier gefüllt wird. Fehlt die Marke (ein Theme ohne
 * `{% seo %}`), kommt vor `</head>`, was das Theme nicht selbst schreibt.
 *
 * Was die Redaktion selbst angibt, geht immer vor. Sonst gilt:
 * - **Titel:** eigener Titel, sonst Titel der Seite mit dem Zusatz aus `publish.seo` — ohne Zusatz, wenn der Name der Site
 *   schon im Titel steht.
 * - **Beschreibung:** eigene, Einleitung, sonst der erste Absatz im Inhalt der fertigen Seite; gekürzt am Wortende.
 * - **Vorschaubild:** Seitenbild, sonst das erste Bild im Inhalt, das Vorschaubild der Site oder ein erzeugtes Bild
 *   ({@see ShareImage}) — jede Seite hat eines.
 * - **Sprache:** `og:locale` mit Land (`de_CH`), Land aus der Sprache oder der Zeitzone der Site.
 * - **Strukturierte Daten:** `WebSite` und `Organization` mit Logo auf der Startseite, sonst `WebPage`; datierte Inhalte
 *   als `Article`/`BlogPosting`; `BreadcrumbList` ab zwei Stufen; dazu, was Module beitragen ({@see PageSeo}).
 *
 * @phpstan-type Tag array{key: string, html: string}
 * @phpstan-type Image array{src: string, width: int|null, height: int|null, alt: string}
 */
final class SeoHead
{
    public const MARK = '<!--basalto:seo-->';

    /** Länge der Beschreibung — so viel zeigen Suchmaschinen meist, bevor sie kürzen. */
    public const DESCRIPTION_LENGTH = 160;

    /** Ein Absatz zählt als Beschreibung ab so vielen Zeichen. */
    private const PARAGRAPH_MIN = 50;

    /** Länder je Sprache, in denen die Sprache Amtssprache ist — das erste ist die Vorgabe. */
    private const TERRITORIES = [
        'de' => ['DE', 'AT', 'CH', 'LI', 'LU', 'BE'], 'fr' => ['FR', 'CH', 'BE', 'LU', 'CA', 'MC'], 'it' => ['IT', 'CH', 'SM', 'VA'],
        'en' => ['US', 'GB', 'IE', 'CA', 'AU', 'NZ'], 'rm' => ['CH'], 'es' => ['ES', 'MX', 'AR'], 'pt' => ['PT', 'BR'], 'nl' => ['NL', 'BE'], 'vi' => ['VN'],
    ];

    public function __construct(private readonly ShareImage $images, private readonly ThemeAssets $assets) {}

    /**
     * @param  array<string, mixed>  $page  Variablen der Seite (Titel, Metadaten, kanonisch, Bild, Sprachfassungen …)
     * @param  string  $suffix  Titel-Zusatz des Themes («{site}» schon ersetzt)
     */
    public function finish(string $html, Site $site, string $locale, array $page, string $suffix, bool $templateNoindex, PageSeo $contrib): string
    {
        $tags = $this->tags($html, $site, $locale, $page, $suffix, $templateNoindex, $contrib);
        $at = strpos($html, self::MARK);
        if ($at !== false) {
            $html = substr_replace($html, implode('', array_column($tags, 'html')), $at, strlen(self::MARK));

            return str_replace(self::MARK, '', $html);
        }
        $end = stripos($html, '</head>');
        if ($end === false) {
            return $html;
        }
        $head = substr($html, 0, $end);
        $missing = array_filter($tags, static fn (array $tag): bool => ! self::present($head, $tag['key']));

        return substr_replace($html, implode('', array_column($missing, 'html')), $end, 0);
    }

    /**
     * @param  array<string, mixed>  $page
     * @return list<Tag>
     */
    public function tags(string $html, Site $site, string $locale, array $page, string $suffix, bool $templateNoindex, PageSeo $contrib): array
    {
        $meta = is_array($page['meta'] ?? null) ? $page['meta'] : [];
        $title = $this->title($page, $meta, $suffix, $site->name, $contrib);
        $body = self::content($html);
        $description = $contrib->description ?? (self::text($meta['description'] ?? null) ?: self::firstParagraph($body));
        $description = $description === null ? null : self::shorten($description, self::DESCRIPTION_LENGTH);
        $robots = $contrib->noindex ? 'noindex, follow' : (is_string($meta['robots'] ?? null) && $meta['robots'] !== '' ? $meta['robots'] : ($templateNoindex ? 'noindex, follow' : null));
        $pageUrl = is_string($page['canonical'] ?? null) && $page['canonical'] !== '' ? $page['canonical'] : (is_string($page['url'] ?? null) ? $page['url'] : null);
        $url = $pageUrl === null ? null : self::withQuery($pageUrl, $contrib->query);
        $origin = $url !== null ? (string) preg_replace('#^(https?://[^/]+).*$#', '$1', $url) : rtrim($site->publicUrl(), '/');
        // Auf dem erzeugten Bild steht der Titel ohne Zusatz — Logo und Adresse nennen die Site schon
        $image = $this->image($site, $locale, $page, $body, $contrib->title ?? self::text($page['title'] ?? null) ?? $title, $origin, $contrib);
        $date = self::date($page['date'] ?? null);
        $type = $contrib->type ?? (($page['is_home'] ?? false) !== true && $date !== null ? 'article' : 'website');
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES);

        $tags = [['key' => 'title', 'html' => sprintf('<title>%s</title>', $e($title))]];
        if ($description !== null) {
            $tags[] = ['key' => 'meta:description', 'html' => sprintf('<meta name="description" content="%s">', $e($description))];
        }
        if ($robots !== null) {
            $tags[] = ['key' => 'meta:robots', 'html' => sprintf('<meta name="robots" content="%s">', $e($robots))];
        }
        if ($url !== null) {
            $tags[] = ['key' => 'link:canonical', 'html' => sprintf('<link rel="canonical" href="%s">', $e($url))];
        }
        foreach ($this->verification($site) as $name => $code) {
            $tags[] = ['key' => 'meta:'.$name, 'html' => sprintf('<meta name="%s" content="%s">', $e($name), $e($code))];
        }
        $og = ['og:type' => $type, 'og:title' => $title, 'og:description' => $description, 'og:url' => $url, 'og:site_name' => $site->name, 'og:locale' => $this->ogLocale($locale, $site)];
        foreach ($og as $property => $value) {
            if ($value !== null && $value !== '') {
                $tags[] = ['key' => $property, 'html' => sprintf('<meta property="%s" content="%s">', $property, $e($value))];
            }
        }
        $alternates = self::alternates($page, $contrib->query);
        foreach ($alternates as $alt) {
            if ($alt['locale'] !== $locale) {
                $tags[] = ['key' => 'og:locale:alternate', 'html' => sprintf('<meta property="og:locale:alternate" content="%s">', $e($this->ogLocale($alt['locale'], $site)))];
            }
        }
        if ($image !== null) {
            $tags[] = ['key' => 'og:image', 'html' => sprintf('<meta property="og:image" content="%s">', $e($image['src']))
                .($image['width'] !== null && $image['height'] !== null ? sprintf('<meta property="og:image:width" content="%d"><meta property="og:image:height" content="%d">', $image['width'], $image['height']) : '')
                .($image['alt'] !== '' ? sprintf('<meta property="og:image:alt" content="%s">', $e($image['alt'])) : '')];
        }
        if ($type === 'article') {
            $published = $contrib->published?->format(DATE_ATOM) ?? $date;
            $modified = $contrib->modified?->format(DATE_ATOM) ?? self::text($page['modified_at'] ?? null);
            if ($published !== null) {
                $tags[] = ['key' => 'article:published_time', 'html' => sprintf('<meta property="article:published_time" content="%s">', $e($published))];
            }
            if ($modified !== null) {
                $tags[] = ['key' => 'article:modified_time', 'html' => sprintf('<meta property="article:modified_time" content="%s">', $e($modified))];
            }
        }
        $tags[] = ['key' => 'meta:twitter:card', 'html' => sprintf('<meta name="twitter:card" content="%s">', $image !== null ? 'summary_large_image' : 'summary')];
        $links = '';
        foreach ($alternates as $alt) {
            $links .= sprintf('<link rel="alternate" hreflang="%s" href="%s">', $e($alt['locale']), $e($alt['url']));
            if ($alt['locale'] === $site->default_locale && count($alternates) > 1) {
                $links .= sprintf('<link rel="alternate" hreflang="x-default" href="%s">', $e($alt['url']));
            }
        }
        if ($links !== '') {
            $tags[] = ['key' => 'hreflang', 'html' => $links];
        }
        $json = $this->structuredData($site, $page, $title, $description, $url, $origin, $locale, $image, $type, $date, $contrib);
        if ($json !== '') {
            $tags[] = ['key' => 'jsonld', 'html' => $json];
        }

        return $tags;
    }

    /**
     * Ein Text auf höchstens `$length` Zeichen, gekürzt am Wortende mit «…» — Leerraum zusammengefasst.
     */
    public static function shorten(string $text, int $length = self::DESCRIPTION_LENGTH): string
    {
        $text = trim((string) preg_replace(['/\s+/u', '/\s+([.,;:!?])/u'], [' ', '$1'], $text));
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        $cut = mb_substr($text, 0, $length - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $length * 0.6) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " \t,;:.–—-").'…';
    }

    /**
     * Der Inhalt der fertigen Seite ohne Skripte, Stile, Navigation und Fuss: `<main>`, sonst `<body>`.
     */
    public static function content(string $html): string
    {
        $body = preg_match('#<main\b[^>]*>(.*)</main>#is', $html, $m) === 1 ? $m[1] : (preg_match('#<body\b[^>]*>(.*)</body>#is', $html, $b) === 1 ? $b[1] : '');

        return (string) preg_replace('#<(script|style|noscript|template|nav|footer|form|dialog)\b[^>]*>.*?</\1>#is', '', $body);
    }

    /** Der erste Absatz mit genug Text — ohne Absätze in Kopf, Seitenleiste und Bildunterschriften. */
    public static function firstParagraph(string $content): ?string
    {
        $content = (string) preg_replace('#<(header|aside|figure|blockquote)\b[^>]*>.*?</\1>#is', '', $content);
        if (preg_match_all('#<p\b[^>]*>(.*?)</p>#is', $content, $m) < 1) {
            return null;
        }
        $fallback = null;
        foreach ($m[1] as $inner) {
            $text = self::text(html_entity_decode(strip_tags((string) $inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($text === null) {
                continue;
            }
            if (mb_strlen($text) >= self::PARAGRAPH_MIN) {
                return $text;
            }
            $fallback ??= mb_strlen($text) >= 20 ? $text : null;
        }

        return $fallback;
    }

    /**
     * Das erste Bild im Inhalt, das sich als Vorschau eignet: kein SVG, keine eingebetteten Daten, nicht winzig.
     *
     * @return Image|null
     */
    public static function firstImage(string $content): ?array
    {
        if (preg_match_all('#<img\b[^>]*>#i', $content, $m) < 1) {
            return null;
        }
        foreach ($m[0] as $tag) {
            $attr = static fn (string $name): ?string => preg_match('#\s'.$name.'\s*=\s*("([^"]*)"|\'([^\']*)\')#i', $tag, $a) === 1 ? html_entity_decode($a[2] !== '' ? $a[2] : ($a[3] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
            $src = self::largest($attr('srcset')) ?? $attr('src');
            if ($src === null || $src === '' || str_starts_with($src, 'data:') || preg_match('#\.svg(\?|$)#i', $src) === 1) {
                continue;
            }
            $width = is_numeric($attr('width')) ? (int) $attr('width') : null;
            $height = is_numeric($attr('height')) ? (int) $attr('height') : null;
            if (($width !== null && $width < 200) || ($height !== null && $height < 100)) {
                continue;
            }

            return ['src' => $src, 'width' => null, 'height' => null, 'alt' => (string) $attr('alt')];
        }

        return null;
    }

    /** Der grösste Kandidat aus `srcset` («a.webp 400w, b.webp 800w» → b.webp). */
    private static function largest(?string $srcset): ?string
    {
        if ($srcset === null || trim($srcset) === '') {
            return null;
        }
        $best = null;
        $size = -1;
        foreach (explode(',', $srcset) as $candidate) {
            $parts = preg_split('/\s+/', trim($candidate)) ?: [];
            $value = isset($parts[1]) ? (float) $parts[1] : 1.0;
            if (($parts[0] ?? '') !== '' && $value > $size) {
                $best = $parts[0];
                $size = $value;
            }
        }

        return $best;
    }

    /**
     * Eine Adresse mit den Parametern der Ansicht (sortiert, ohne leere).
     *
     * @param  array<string, string>  $query
     */
    public static function withQuery(string $url, array $query): string
    {
        if ($query === []) {
            return $url;
        }
        $base = strtok($url, '?#') ?: $url;

        return $base.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** Ob der Kopf des Themes eine Angabe schon selbst schreibt. */
    private static function present(string $head, string $key): bool
    {
        $q = static fn (string $v): string => preg_quote($v, '#');

        return match (true) {
            $key === 'title' => preg_match('#<title[\s>]#i', $head) === 1,
            $key === 'link:canonical' => preg_match('#<link\b[^>]*rel=["\']?canonical#i', $head) === 1,
            $key === 'hreflang' => stripos($head, 'hreflang=') !== false,
            $key === 'jsonld' => stripos($head, 'application/ld+json') !== false,
            str_starts_with($key, 'meta:') => preg_match('#<meta\b[^>]*name=["\']'.$q(substr($key, 5)).'["\']#i', $head) === 1,
            default => preg_match('#<meta\b[^>]*property=["\']'.$q($key).'["\']#i', $head) === 1,
        };
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $meta
     */
    private function title(array $page, array $meta, string $suffix, string $siteName, PageSeo $contrib): string
    {
        $own = self::text($meta['title'] ?? null);
        if ($contrib->title === null && $own !== null) {
            return $own;
        }
        $title = $contrib->title ?? (self::text($page['title'] ?? null) ?? $siteName);

        return $suffix !== '' && mb_stripos($title, $siteName) === false ? $title.$suffix : $title;
    }

    /**
     * @param  array<string, mixed>  $page
     * @return Image|null
     */
    private function image(Site $site, string $locale, array $page, string $body, string $title, string $origin, PageSeo $contrib): ?array
    {
        $own = is_array($page['image'] ?? null) && is_string($page['image']['src'] ?? null) && $page['image']['src'] !== ''
            ? ['src' => $page['image']['src'], 'width' => is_numeric($page['image']['width'] ?? null) ? (int) $page['image']['width'] : null,
                'height' => is_numeric($page['image']['height'] ?? null) ? (int) $page['image']['height'] : null, 'alt' => (string) ($page['image']['alt'] ?? '')]
            : null;
        $image = $contrib->image ?? $own ?? self::firstImage($body) ?? $this->siteImage($site, $locale) ?? ($this->images->available()
            ? ['src' => $this->images->url($site, $title, $locale), 'width' => ShareImage::WIDTH, 'height' => ShareImage::HEIGHT, 'alt' => $title] : null);
        if ($image === null) {
            return null;
        }
        if (str_starts_with($image['src'], '//')) {
            $image['src'] = 'https:'.$image['src'];
        } elseif (! preg_match('#^https?://#i', $image['src'])) {
            $image['src'] = $origin.'/'.ltrim($image['src'], '/');
        }

        return $image;
    }

    /**
     * Das Vorschaubild der Site (Verwaltung › Suchmaschinen), wenn eines gewählt ist.
     *
     * @return Image|null
     */
    private function siteImage(Site $site, string $locale): ?array
    {
        $id = $site->setting('seo.share_image');
        if (! is_numeric($id)) {
            return null;
        }
        $media = Media::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereKey((int) $id)->first();
        if ($media === null) {
            return null;
        }

        $alt = is_array($media->alt) ? ($media->alt[$locale] ?? $media->alt[$site->default_locale] ?? '') : '';

        // Über die Auslieferung der Site, nie als befristete Adresse eines Buckets — Netzwerke behalten das Bild lange
        return ['src' => route('basalto.media.show', ['media' => $media->id], false), 'width' => $media->width, 'height' => $media->height, 'alt' => (string) $alt];
    }

    /** @return array<string, string> Name des Meta-Tags → Bestätigungscode */
    private function verification(Site $site): array
    {
        $out = [];
        foreach (['google-site-verification' => 'seo.google_verification', 'msvalidate.01' => 'seo.bing_verification'] as $name => $key) {
            $code = $site->setting($key);
            if (is_string($code) && preg_match('/^[A-Za-z0-9_\-]{8,120}$/', $code) === 1) {
                $out[$name] = $code;
            }
        }

        return $out;
    }

    /** `og:locale` als Sprache_Land: aus dem Code («de-CH»), sonst das Land der Zeitzone, wenn die Sprache dort gilt. */
    public function ogLocale(string $code, Site $site): string
    {
        if (preg_match('/^([a-z]{2,3})[-_]([a-z]{2})$/i', $code, $m) === 1) {
            return strtolower($m[1]).'_'.strtoupper($m[2]);
        }
        $language = strtolower($code);
        $territories = self::TERRITORIES[$language] ?? [strtoupper($language)];
        try {
            $location = (new DateTimeZone($site->timezone ?: 'UTC'))->getLocation();
            $country = $location === false ? '' : $location['country_code'];
        } catch (Throwable) {
            $country = '';
        }

        return $language.'_'.(in_array($country, $territories, true) ? $country : $territories[0]);
    }

    /**
     * Sprachfassungen für hreflang — mit den Parametern der Ansicht.
     *
     * @param  array<string, mixed>  $page
     * @param  array<string, string>  $query
     * @return list<array{locale: string, url: string}>
     */
    private static function alternates(array $page, array $query): array
    {
        $list = is_array($page['hreflang'] ?? null) ? $page['hreflang'] : (is_array($page['alternates'] ?? null) ? $page['alternates'] : []);
        $out = [];
        foreach ($list as $alt) {
            if (is_array($alt) && is_string($alt['locale'] ?? null) && is_string($alt['url'] ?? null)) {
                $out[] = ['locale' => $alt['locale'], 'url' => self::withQuery($alt['url'], $query)];
            }
        }

        return $out;
    }

    /**
     * Schema.org als JSON-LD; `</` wird maskiert, damit kein Inhalt das Skript beenden kann.
     *
     * @param  array<string, mixed>  $page
     * @param  Image|null  $image
     */
    private function structuredData(Site $site, array $page, string $title, ?string $description, ?string $url, string $origin, string $locale, ?array $image, string $type, ?string $date, PageSeo $contrib): string
    {
        if ($url === null) {
            return '';
        }
        $organization = ['@type' => 'Organization', '@id' => $origin.'/#organisation', 'name' => $site->name, 'url' => $origin.'/'];
        $logo = $this->logo($site, $origin);
        if ($logo !== null) {
            $organization['logo'] = ['@type' => 'ImageObject', 'url' => $logo];
        }
        $graph = [];
        if (($page['is_home'] ?? false) === true) {
            $graph[] = array_filter(['@type' => 'WebSite', '@id' => $origin.'/#website', 'name' => $site->name, 'url' => $url, 'inLanguage' => $locale, 'description' => $description,
                'publisher' => ['@id' => $organization['@id']]]);
            $graph[] = $organization;
        } else {
            $graph[] = array_filter(['@type' => 'WebPage', 'name' => $title, 'url' => $url, 'inLanguage' => $locale, 'description' => $description,
                'isPartOf' => ['@type' => 'WebSite', 'name' => $site->name, 'url' => $origin.'/'], 'primaryImageOfPage' => $image === null ? null : ['@type' => 'ImageObject', 'url' => $image['src']]]);
        }
        if ($type === 'article') {
            $author = $page['author'] ?? null;
            $author = is_array($author) ? (self::text($author['title'] ?? null) ?? self::text($author['name'] ?? null)) : self::text($author);
            $graph[] = array_filter(['@type' => ($page['type'] ?? null) === 'post' ? 'BlogPosting' : 'Article', 'headline' => mb_substr(self::text($page['title'] ?? null) ?? $title, 0, 110),
                'description' => $description, 'image' => $image === null ? null : [$image['src']], 'datePublished' => $contrib->published?->format(DATE_ATOM) ?? $date,
                'dateModified' => $contrib->modified?->format(DATE_ATOM) ?? self::text($page['modified_at'] ?? null), 'inLanguage' => $locale, 'mainEntityOfPage' => $url,
                'author' => $author !== null ? ['@type' => 'Person', 'name' => $author] : ['@id' => $organization['@id']], 'publisher' => $organization]);
        }
        $crumbs = is_array($page['breadcrumbs'] ?? null) ? array_values($page['breadcrumbs']) : [];
        if (count($crumbs) >= 2) {
            $items = [];
            foreach ($crumbs as $i => $crumb) {
                if (is_array($crumb) && is_string($crumb['label'] ?? null)) {
                    $href = is_string($crumb['href'] ?? null) ? $crumb['href'] : '';
                    $items[] = array_filter(['@type' => 'ListItem', 'position' => $i + 1, 'name' => $crumb['label'], 'item' => $href === '' ? null : (str_starts_with($href, 'http') ? $href : $origin.$href)]);
                }
            }
            $graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
        }
        foreach ($contrib->data as $node) {
            $graph[] = $node;
        }
        $json = (string) json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

        return '<script type="application/ld+json">'.$json.'</script>';
    }

    /** Volle Adresse des Logos der Site (Theme oder Mediathek). */
    private function logo(Site $site, string $origin): ?string
    {
        try {
            $logo = $this->assets->logoUrl($site);
        } catch (Throwable) {
            return null;
        }
        if ($logo === null || $logo === '') {
            return null;
        }

        return preg_match('#^https?://#i', $logo) === 1 ? $logo : $origin.'/'.ltrim($logo, '/');
    }

    /** Datum als ISO 8601 (Tag oder Zeitpunkt) oder null. */
    private static function date(mixed $value): ?string
    {
        $value = self::text($value);
        if ($value === null || strtotime($value) === false) {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : date(DATE_ATOM, (int) strtotime($value));
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : $value;
    }
}
