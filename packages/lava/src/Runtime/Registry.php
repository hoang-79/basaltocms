<?php

declare(strict_types=1);

namespace Basalto\Lava\Runtime;

use Closure;
use LogicException;

/**
 * Was Lava aufrufen darf: Funktionen und Filter (dieselbe Liste), Tags, Feldtypen und zusätzliche Eigenschaften
 * je Schlüsselwort. Der Kern registriert Namen ohne Punkt; Module registrieren über module('handle') und nur
 * unter ihrem Präfix («example.cart») — eine Kollision ist damit unmöglich (L-12). Themes registrieren nie etwas (R-99).
 */
final class Registry
{
    /** @var list<string> Feldtypen des Kerns — die Grundmenge, Module ergänzen über module()->fieldType() */
    public const FIELD_TYPES = ['text', 'textarea', 'richtext', 'blocks', 'markdown', 'number', 'checkbox', 'bool', 'date', 'datetime', 'select', 'tags',
        'image', 'gallery', 'file', 'link', 'relation', 'repeater', 'slug', 'email', 'url', 'color', 'code', 'seo', 'json'];

    /** @var list<string> Feldtypen eines Formulars (M-11, Baukasten) — «explanation» ist Text ohne Eingabe, «group» fasst Felder zusammen */
    public const FORM_FIELD_TYPES = ['text', 'email', 'number', 'tel', 'url', 'date', 'textarea', 'select', 'radio', 'checkbox', 'file', 'range',
        'hidden', 'explanation', 'group'];

    /** @var array<string, Closure> */
    private array $functions = [];

    /** @var array<string, Closure> */
    private array $tags = [];

    /** @var array<string, array<string, mixed>> Feldtyp → Optionen */
    private array $fieldTypes = [];

    /** @var array<string, list<string>> Schlüsselwort → zusätzliche Eigenschaften aus Modulen */
    private array $properties = [];

    /**
     * Arten von Lava-Teilen (`part name: art { … }`, M-28): Beschriftung, erlaubte Einstellungen, Herkunft und —
     * bei Modulen — die Ausgabe. Die Arten des Kerns rendert der PageRenderer selbst.
     *
     * @var array<string, array{label: string, settings: list<string>, origin: string, render: Closure|null}>
     */
    private array $parts = [];

    /**
     * Die Standard-Registrierung des Kerns. Module erweitern nicht hier, sondern die Instanz im Container
     * (`$this->app->extend(Registry::class, …)`, K-12) — Themes registrieren nie etwas (R-99).
     */
    public static function standard(): self
    {
        $r = new self;
        foreach (self::FIELD_TYPES as $type) {
            $r->fieldType($type);
        }
        $r->function('count', static fn (mixed $v): int => is_countable($v) ? count($v) : 0);
        $r->function('length', static fn (mixed $v): int => is_string($v) ? mb_strlen($v) : (is_countable($v) ? count($v) : 0));
        $r->function('upper', static fn (mixed $v): string => mb_strtoupper((string) $v));
        $r->function('lower', static fn (mixed $v): string => mb_strtolower((string) $v));
        $r->function('truncate', static fn (mixed $v, int $n = 100, string $end = '…'): string => mb_strlen((string) $v) > $n ? rtrim(mb_substr((string) $v, 0, $n)).$end : (string) $v);
        $r->function('date', static function (mixed $v, string $format = 'd.m.Y'): string {
            $time = is_numeric($v) ? (int) $v : strtotime((string) $v);

            return $time === false ? '' : date($format, $time);
        });
        $r->function('join', static fn (mixed $v, string $sep = ', '): string => is_iterable($v) ? implode($sep, array_map(static fn (mixed $x): string => (string) $x, is_array($v) ? $v : iterator_to_array($v))) : (string) $v);
        $r->function('first', static fn (mixed $v): mixed => is_array($v) ? ($v[array_key_first($v)] ?? null) : null);
        $r->function('last', static fn (mixed $v): mixed => is_array($v) ? ($v[array_key_last($v)] ?? null) : null);
        $r->function('keys', static fn (mixed $v): array => is_array($v) ? array_keys($v) : []);
        $r->function('default', static fn (mixed $v, mixed $d = ''): mixed => ($v === null || $v === '' || $v === []) ? $d : $v);
        $r->function('json', static fn (mixed $v): string => (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $r->function('range', static fn (int $a, int $b): array => abs($b - $a) > 10_000 ? throw new LavaRuntimeException('range: höchstens 10 000 Elemente') : range($a, $b));
        $r->function('url', static fn (mixed $v): string => str_starts_with((string) $v, 'http') ? (string) $v : '/'.ltrim((string) $v, '/'));
        $r->function('t', static fn (mixed $v): string => (string) $v);
        $r->function('nl2br', static fn (mixed $v): string => nl2br(htmlspecialchars((string) $v, ENT_QUOTES)));
        $r->function('replace', static fn (mixed $v, string $from, string $to): string => str_replace($from, $to, (string) $v));
        $r->function('slug', static fn (mixed $v): string => trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower((string) $v)), '-'));

        $r->tag('t', static fn (array $args): string => htmlspecialchars((string) ($args[0] ?? ''), ENT_QUOTES));
        $r->tag('image', static function (array $args, array $named): string {
            $image = $args[0] ?? null;
            if (! $image) {
                return '';
            }
            $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES);
            $size = (string) ($named['size'] ?? 'content');
            $alt = is_array($image) ? ($image['alt'] ?? '') : '';
            $class = isset($named['class']) ? sprintf(' class="%s"', $e($named['class'])) : '';
            $variant = is_array($image) && is_array($image['variants'][$size] ?? null) ? $image['variants'][$size] : null;
            if ($variant === null) {
                $src = is_array($image) ? ($image['src'] ?? '') : (string) $image;

                return sprintf('<img src="%s" alt="%s" data-size="%s" loading="lazy"%s>', $e($src), $e($alt), $e($size), $class);
            }
            // Responsiv (K-09): AVIF-Quelle, WebP mit Dichten, Masse gegen Layoutsprünge, Lazy nach Theme
            $sizes = is_string($variant['sizes'] ?? null) && $variant['sizes'] !== '' ? sprintf(' sizes="%s"', $e($variant['sizes'])) : '';
            $srcset = is_string($variant['srcset'] ?? null) && $variant['srcset'] !== '' ? sprintf(' srcset="%s"', $e($variant['srcset'])) : '';
            $loading = ($variant['lazy'] ?? true) === false ? 'eager' : 'lazy';
            $img = sprintf('<img src="%s"%s%s width="%d" height="%d" alt="%s" loading="%s" decoding="async"%s>', $e($variant['src'] ?? ''), $srcset, $sizes, (int) ($variant['width'] ?? 0), (int) ($variant['height'] ?? 0), $e($alt), $loading, $class);
            if (is_string($variant['avif'] ?? null) && $variant['avif'] !== '') {
                return sprintf('<picture><source type="image/avif" srcset="%s"%s>%s</picture>', $e($variant['avif']), $sizes, $img);
            }

            return $img;
        });
        $r->tag('link', static function (array $args, array $named): string {
            $link = $args[0] ?? null;
            if (! $link) {
                return '';
            }
            $href = is_array($link) ? ($link['href'] ?? '#') : (string) $link;
            // «label:» im Tag gewinnt (z. B. der Kartentitel), sonst die Beschriftung des Links, sonst die Adresse
            $label = isset($named['label']) && (string) $named['label'] !== '' ? (string) $named['label'] : (is_array($link) ? ($link['label'] ?? $href) : $href);
            $class = isset($named['class']) ? sprintf(' class="%s"', htmlspecialchars((string) $named['class'], ENT_QUOTES)) : '';

            return sprintf('<a href="%s"%s>%s</a>', htmlspecialchars((string) $href, ENT_QUOTES), $class, htmlspecialchars((string) $label, ENT_QUOTES));
        });
        $r->tag('seo', static fn (array $args, array $named, Context $ctx): string => sprintf('<title>%s</title><meta name="description" content="%s">',
            htmlspecialchars((string) ($ctx->get('page')['title'] ?? $ctx->get('site')['name'] ?? ''), ENT_QUOTES), htmlspecialchars((string) ($ctx->get('page')['intro'] ?? ''), ENT_QUOTES)));
        $r->tag('assets', static fn (): string => '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">');
        // TB 21: Umschalter hell/dunkel — von der Anwendung ersetzt, wenn das Theme dunkle Farben hat
        $r->tag('appearance', static fn (): string => '');
        $r->tag('pagination', static fn (): string => '');
        // Von der Anwendung beim Rendern ersetzt (M-12, M-13): Suche, Listen von Inhalten, Kategorien, Archiv
        $r->tag('search', static fn (): string => '');
        $r->tag('categories', static fn (): string => '');
        $r->tag('archive', static fn (): string => '');
        $r->function('entries', static fn (mixed $type = null, mixed $per = 10): array => []);
        // Adresse einer Datei des Themes unter assets/ (Bilder, Grafiken) — von der Anwendung beim Rendern ersetzt
        $r->function('asset', static fn (mixed $path = null): string => '');
        // TB 56: ein Rechtstext aus docs/recht als HTML — von der Anwendung beim Rendern ersetzt
        $r->function('legal', static fn (mixed $slug = null): string => '');
        // Lava-Teile des Kerns (Station C): Navigation, Brotkrumen, Sprachwechsel, globaler Inhalt, Formular
        $r->part('nav', 'Navigation', ['source', 'depth', 'start', 'only', 'exclude', 'label']);
        $r->part('breadcrumbs', 'Brotkrumen', []);
        $r->part('languages', 'Sprachwechsel', []);
        $r->part('global', 'Globaler Inhalt', ['source']);
        $r->part('form', 'Formular', ['source']);
        // TB 10 Scheibe 3 (M-28): HTML-Sitemap aus einer Navigation, Suche, Liste eines Typs, HTML als Markup-Zaun
        $r->part('sitemap', 'HTML-Sitemap', ['source', 'depth', 'start', 'only', 'exclude', 'label']);
        $r->part('search', 'Suche', []);
        $r->part('list', 'Liste', ['source', 'per', 'label']);
        $r->part('html', 'HTML', []);
        // Brotkrumen und Sprachwechsel (M-07) aus den Seitenvariablen — eigenes Markup über «{% each page.breadcrumbs %}» / «page.alternates»
        $r->tag('breadcrumbs', static fn (array $args, array $named, Context $ctx): string => self::breadcrumbsMarkup(is_array($ctx->get('page')) ? $ctx->get('page') : []));
        $r->tag('languages', static fn (array $args, array $named, Context $ctx): string => self::languagesMarkup(is_array($ctx->get('page')) ? $ctx->get('page') : []));

        return $r;
    }

    /**
     * Die Sicht eines Moduls auf die Registrierung: jeder Name muss mit «handle.» beginnen, belegte Namen sind tabu.
     */
    public function module(string $handle): ModuleRegistry
    {
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $handle)) {
            throw new LogicException(sprintf('Modul-Handle «%s» ist ungültig — erlaubt sind Kleinbuchstaben, Ziffern und Unterstrich', $handle));
        }

        return new ModuleRegistry($this, $handle);
    }

    /** Kern-Funktion oder -Filter — Namen ohne Punkt; Module gehen über module(). */
    public function function(string $name, Closure $fn): self
    {
        $this->functions[$this->coreName($name, 'Funktion')] = $fn;

        return $this;
    }

    /** Kern-Tag — Namen ohne Punkt; Module gehen über module(). */
    public function tag(string $name, Closure $fn): self
    {
        $this->tags[$this->coreName($name, 'Tag')] = $fn;

        return $this;
    }

    /**
     * Kern-Feldtyp mit Optionen (z. B. «['input' => 'number']» für das Backend-Formular).
     *
     * @param  array<string, mixed>  $options
     */
    public function fieldType(string $name, array $options = []): self
    {
        $this->fieldTypes[$this->coreName($name, 'Feldtyp')] = $options;

        return $this;
    }

    /** Eine zusätzliche Eigenschaft eines Schlüsselworts aus dem Kern — ohne Punkt; Module gehen über module(). */
    public function property(string $keyword, string $name): self
    {
        $this->properties[$keyword][] = $this->coreName($name, 'Eigenschaft');

        return $this;
    }

    /**
     * Eine Art von Lava-Teil des Kerns (Module über module()->part()).
     *
     * @param  list<string>  $settings
     */
    public function part(string $name, string $label, array $settings, ?Closure $render = null): self
    {
        $this->parts[$this->coreName($name, 'Teil')] = ['label' => $label, 'settings' => $settings, 'origin' => 'Kern', 'render' => $render];

        return $this;
    }

    public function hasPart(string $name): bool
    {
        return isset($this->parts[$name]);
    }

    /** @return array<string, array{label: string, settings: list<string>, origin: string, render: Closure|null}> */
    public function parts(): array
    {
        return $this->parts;
    }

    /**
     * Registrierung unter einem Modul-Präfix — nur über ModuleRegistry erreichbar.
     *
     * @internal
     */
    public function registerScoped(string $kind, string $name, mixed $payload, ?string $keyword = null): void
    {
        $exists = match ($kind) {
            'function' => isset($this->functions[$name]),
            'tag' => isset($this->tags[$name]),
            'fieldType' => isset($this->fieldTypes[$name]),
            'property' => in_array($name, $this->properties[(string) $keyword] ?? [], true),
            'part' => isset($this->parts[$name]),
            default => throw new LogicException(sprintf('Unbekannte Registrierungsart «%s»', $kind)),
        };
        if ($exists) {
            throw new LogicException(sprintf('Kollision: %s «%s» ist schon registriert', self::label($kind), $name));
        }
        match ($kind) {
            'function' => $this->functions[$name] = $payload instanceof Closure ? $payload : throw new LogicException('Funktion braucht eine Closure'),
            'tag' => $this->tags[$name] = $payload instanceof Closure ? $payload : throw new LogicException('Tag braucht eine Closure'),
            'fieldType' => $this->fieldTypes[$name] = is_array($payload) ? $payload : [],
            'property' => $this->properties[(string) $keyword][] = $name,
            'part' => $this->parts[$name] = self::partSpec($payload),
        };
    }

    public function hasFunction(string $name): bool
    {
        return isset($this->functions[$name]);
    }

    /** Die registrierte Funktion bzw. der Filter selbst — für die Signatur im Sprachdienst (L-14); null, wenn unbekannt. */
    public function functionClosure(string $name): ?Closure
    {
        return $this->functions[$name] ?? null;
    }

    public function hasTag(string $name): bool
    {
        return isset($this->tags[$name]);
    }

    public function hasFieldType(string $name): bool
    {
        return isset($this->fieldTypes[$name]);
    }

    /**
     * @param  list<mixed>  $args
     * @param  array<string, mixed>  $named
     */
    public function callFunction(string $name, array $args, array $named, int $line): mixed
    {
        if (! isset($this->functions[$name])) {
            throw new LavaRuntimeException(sprintf('Unbekannte Funktion «%s» — nur registrierte Funktionen sind erlaubt', $name), $line);
        }

        return $this->guarded(sprintf('Funktion «%s»', $name), $line, fn (): mixed => ($this->functions[$name])(...$args, ...$named));
    }

    /**
     * @param  list<mixed>  $args
     * @param  array<string, mixed>  $named
     */
    public function callTag(string $name, array $args, array $named, Context $ctx, int $line): string
    {
        if (! isset($this->tags[$name])) {
            throw new LavaRuntimeException(sprintf('Unbekanntes Tag «%s»', $name), $line);
        }

        return (string) $this->guarded(sprintf('Tag «%s»', $name), $line, fn (): mixed => ($this->tags[$name])($args, $named, $ctx));
    }

    /** @return list<string> */
    public function functionNames(): array
    {
        return array_keys($this->functions);
    }

    /** @return list<string> */
    public function tagNames(): array
    {
        return array_keys($this->tags);
    }

    /** @return list<string> Kern-Feldtypen plus die der Module */
    public function fieldTypes(): array
    {
        return array_keys($this->fieldTypes);
    }

    /** @return array<string, mixed> */
    public function fieldTypeOptions(string $name): array
    {
        return $this->fieldTypes[$name] ?? [];
    }

    /** @return list<string> zusätzliche Eigenschaften eines Schlüsselworts aus Kern und Modulen */
    public function properties(string $keyword): array
    {
        return $this->properties[$keyword] ?? [];
    }

    /** @return list<string> alle Funktions- und Filternamen mit Punkt — für Parser und Markup-Cache */
    public function dottedFunctionNames(): array
    {
        return array_values(array_filter(array_keys($this->functions), static fn (string $n): bool => str_contains($n, '.')));
    }

    /**
     * Ein PHP-Fehler in einer registrierten Funktion (falscher Typ, fehlendes Argument) wird zum Lava-Fehler mit Zeile —
     * das Theme sieht nie einen PHP-Stack (L-16).
     *
     * @param  Closure(): mixed  $call
     */
    private function guarded(string $what, int $line, Closure $call): mixed
    {
        try {
            return $call();
        } catch (LavaRuntimeException $e) {
            throw $e->lavaLine > 0 ? $e : new LavaRuntimeException($e->getMessage(), $line, $e);
        } catch (\Error $e) {
            throw new LavaRuntimeException(sprintf('%s: %s', $what, $e->getMessage()), $line, $e);
        }
    }

    private function coreName(string $name, string $label): string
    {
        if (str_contains($name, '.')) {
            throw new LogicException(sprintf('%s «%s»: Namen mit Punkt registrieren Module über module(\'handle\')', $label, $name));
        }
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new LogicException(sprintf('%s «%s»: ungültiger Name', $label, $name));
        }

        return $name;
    }

    /** @return array{label: string, settings: list<string>, origin: string, render: Closure|null} */
    private static function partSpec(mixed $payload): array
    {
        if (! is_array($payload) || ! is_string($payload['label'] ?? null) || ! is_array($payload['settings'] ?? null)) {
            throw new LogicException('Teil braucht Beschriftung und Einstellungen');
        }
        $render = $payload['render'] ?? null;

        return ['label' => $payload['label'], 'settings' => array_values(array_map(static fn (mixed $s): string => (string) $s, $payload['settings'])),
            'origin' => (string) ($payload['origin'] ?? 'Kern'), 'render' => $render instanceof Closure ? $render : null];
    }

    private static function label(string $kind): string
    {
        return match ($kind) {
            'function' => 'Funktion',
            'tag' => 'Tag',
            'fieldType' => 'Feldtyp',
            'part' => 'Teil',
            default => 'Eigenschaft',
        };
    }

    /** @param  array<string, mixed>  $page */
    public static function breadcrumbsMarkup(array $page): string
    {
        $crumbs = is_array($page['breadcrumbs'] ?? null) ? $page['breadcrumbs'] : [];
        if ($crumbs === []) {
            return '';
        }
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES);
        $html = '<nav class="breadcrumbs" aria-label="Brotkrumen"><ol>';
        foreach ($crumbs as $crumb) {
            if (! is_array($crumb)) {
                continue;
            }
            $html .= ($crumb['current'] ?? false) === true
                ? sprintf('<li><span aria-current="page">%s</span></li>', $e($crumb['label'] ?? ''))
                : sprintf('<li><a href="%s">%s</a></li>', $e($crumb['href'] ?? '#'), $e($crumb['label'] ?? ''));
        }

        return $html.'</ol></nav>';
    }

    /** @param  array<string, mixed>  $page */
    public static function languagesMarkup(array $page): string
    {
        $alternates = is_array($page['alternates'] ?? null) ? $page['alternates'] : [];
        if (count($alternates) < 2) {
            return '';
        }
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES);
        // Beschriftung in der Sprache der Seite — «Sprache» auf einer englischen Seite liest ein Screenreader falsch vor
        $label = match (substr((string) ($page['locale'] ?? 'de'), 0, 2)) {
            'de' => 'Sprache', 'fr' => 'Langue', 'it' => 'Lingua', default => 'Language',
        };
        $html = sprintf('<nav class="languages" aria-label="%s"><ul>', $label);
        foreach ($alternates as $alt) {
            if (! is_array($alt)) {
                continue;
            }
            $locale = (string) ($alt['locale'] ?? '');
            $html .= $locale === ($page['locale'] ?? null)
                ? sprintf('<li><span aria-current="true" lang="%s">%s</span></li>', $e($locale), $e(strtoupper($locale)))
                : sprintf('<li><a href="%s" hreflang="%s" lang="%s">%s</a></li>', $e($alt['url'] ?? '#'), $e($locale), $e($locale), $e(strtoupper($locale)));
        }

        return $html.'</ul></nav>';
    }
}
