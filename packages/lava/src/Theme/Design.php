<?php

declare(strict_types=1);

namespace Basalto\Lava\Theme;

use Basalto\Lava\Structure\Definition;

/**
 * Station B als Werte (L-10): Farben, Schriften, Abstände, Radius, Stylesheets und Logo aus `design` — daraus CSS-Variablen
 * für den Kopf jeder Seite, ein Tailwind-Theme für Theme-Autorinnen und die Kontrastprüfung nach WCAG 2.2 (1.4.3).
 * Überschreibungen aus dem Backend (M-15) legen sich über die Werte des Themes, ohne Build.
 *
 * Dunkle Darstellung (TB 21): `color text: #23262b dark #ebe8e3` — der zweite Wert gilt, wenn Besucher dunkel
 * wünschen (`prefers-color-scheme`) oder auf der Seite «dunkel» wählen (`data-theme="dark"` am `<html>`, Tag
 * `{% appearance %}`); «hell» gewählt (`data-theme="light"`) bleibt hell. Farben ohne dunklen Wert gelten in beiden.
 *
 * Paare für den Kontrast ergeben sich aus den Namen: `text`, `muted` und `link` auf `background`, `X-text` (oder `on-X`)
 * auf `X` — so prüft `basalto:check`, ohne dass ein Theme die Rollen eigens erklären muss.
 *
 * @phpstan-type Font array{family: string, weight: int|null, src: string|null, fallback: 'sans'|'serif'|'mono'}
 * @phpstan-type Overrides array{colors?: array<string, string>, fonts?: array<string, string>, logo?: string|null}
 */
final class Design
{
    /** Mindestkontrast für normalen Text (WCAG 2.2, 1.4.3 AA). */
    public const MIN_CONTRAST = 4.5;

    private const FALLBACKS = [
        'sans' => 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
        'serif' => 'ui-serif, Georgia, Cambria, "Times New Roman", serif',
        'mono' => 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
    ];

    /**
     * @param  array<string, string>  $colors  Name → Farbwert (#rgb, #rrggbb, #rrggbbaa)
     * @param  array<string, Font>  $fonts
     * @param  list<int>  $space  Abstandsstufen in Pixel
     * @param  list<string>  $styles  Stylesheets im Theme-Ordner, z. B. «assets/theme.css»
     */
    public function __construct(
        public readonly array $colors = [],
        public readonly array $fonts = [],
        public readonly array $space = [],
        public readonly ?int $radius = null,
        public readonly array $styles = [],
        public readonly ?string $logo = null,
        /** @var array<string, string> Name → Farbwert der dunklen Darstellung */
        public readonly array $dark = [],
    ) {}

    public static function of(Theme $theme): self
    {
        $design = $theme->document->find('design');

        return $design === null ? new self : self::fromDefinition($design);
    }

    public static function fromDefinition(Definition $design): self
    {
        $colors = [];
        $dark = [];
        foreach ($design->childrenOf('color') as $color) {
            $value = $color->typed[0] ?? null;
            if ($color->name !== null && $value !== null) {
                $colors[$color->name] = strtolower($value->text());
                $night = self::after(array_map(static fn ($v): string => $v->text(), $color->typed), 'dark');
                if ($night !== null && self::isColor($night)) {
                    $dark[$color->name] = strtolower($night);
                }
            }
        }
        $fonts = [];
        foreach ($design->childrenOf('font') as $font) {
            if ($font->name === null || ! isset($font->typed[0])) {
                continue;
            }
            $words = array_map(static fn ($v): string => $v->text(), $font->typed);
            $weight = self::after($words, 'weight');
            $fonts[$font->name] = [
                'family' => $words[0],
                'weight' => is_numeric($weight) ? (int) $weight : null,
                'src' => self::after($words, 'src'),
                'fallback' => self::fallbackOf(self::after($words, 'fallback'), $font->name, $words[0]),
            ];
        }
        $space = [];
        foreach ($design->property('space')->values ?? [] as $value) {
            if (is_numeric($value->text())) {
                $space[] = (int) $value->text();
            }
        }
        $radius = $design->value('radius');
        $styles = [];
        foreach ($design->property('css')->values ?? [] as $value) {
            $styles[] = $value->text();
        }

        return new self($colors, $fonts, $space, is_numeric($radius) ? (int) $radius : null, $styles, $design->value('logo'), $dark);
    }

    /**
     * Überschreibungen aus dem Backend: nur Namen, die das Theme kennt, und nur gültige Werte — ein Theme-Wechsel lässt
     * so keine fremden Variablen zurück.
     *
     * @param  Overrides  $overrides
     */
    public function with(array $overrides): self
    {
        $colors = $this->colors;
        foreach ($overrides['colors'] ?? [] as $name => $value) {
            if (isset($colors[$name]) && self::isColor($value)) {
                $colors[$name] = strtolower($value);
            }
        }
        $fonts = $this->fonts;
        foreach ($overrides['fonts'] ?? [] as $name => $family) {
            $family = self::cleanFamily($family);
            if (isset($fonts[$name]) && $family !== '') {
                $fonts[$name]['family'] = $family;
                $fonts[$name]['src'] = null;
            }
        }
        $logo = $overrides['logo'] ?? null;

        return new self($colors, $fonts, $this->space, $this->radius, $this->styles, is_string($logo) && $logo !== '' ? $logo : $this->logo, $this->dark);
    }

    /** @return array<string, string> CSS-Variable → Wert */
    public function variables(): array
    {
        $vars = [];
        foreach ($this->colors as $name => $value) {
            $vars['--color-'.$name] = $value;
        }
        foreach ($this->fonts as $name => $font) {
            $vars['--font-'.$name] = self::familyList($font);
            if ($font['weight'] !== null) {
                $vars['--font-'.$name.'-weight'] = (string) $font['weight'];
            }
        }
        foreach ($this->space as $i => $px) {
            $vars['--space-'.($i + 1)] = $px === 0 ? '0' : ($px / 16).'rem';
        }
        if ($this->radius !== null) {
            $vars['--radius'] = $this->radius === 0 ? '0' : $this->radius.'px';
        }

        return $vars;
    }

    /**
     * Der Block für den Kopf der Seite: `@font-face` für Schriften mit eigener Datei und `:root` mit allen Variablen.
     *
     * @param  callable(string): ?string  $assetUrl  Pfad im Theme → öffentliche Adresse (null: Datei fehlt)
     */
    public function css(callable $assetUrl): string
    {
        $css = '';
        foreach ($this->fonts as $font) {
            $url = $font['src'] !== null ? $assetUrl($font['src']) : null;
            if ($url !== null) {
                $css .= sprintf('@font-face{font-family:"%s";src:url("%s") format("%s");font-weight:%s;font-display:swap}', $font['family'], $url, str_ends_with((string) $font['src'], '.woff') ? 'woff' : 'woff2', $font['weight'] ?? '100 900');
            }
        }
        $declarations = [];
        foreach ($this->variables() as $name => $value) {
            $declarations[] = $name.':'.$value;
        }

        $css .= $declarations === [] ? '' : ':root{'.implode(';', $declarations).'}';
        if ($this->dark !== []) {
            $night = ['color-scheme:dark'];
            foreach ($this->dark as $name => $value) {
                $night[] = '--color-'.$name.':'.$value;
            }
            $block = implode(';', $night);
            // Wunsch des Systems, ausser «hell» ist gewählt — und «dunkel» gewählt gilt immer
            $css .= '@media (prefers-color-scheme:dark){:root:not([data-theme=light]){'.$block.'}}:root[data-theme=dark]{'.$block.'}';
        }

        return $css;
    }

    /** Hat das Theme eine dunkle Darstellung? Dann gibt es den Umschalter `{% appearance %}`. */
    public function hasDark(): bool
    {
        return $this->dark !== [];
    }

    /**
     * Farbpaare unter dem Mindestkontrast in der dunklen Darstellung (dunkle Werte über den hellen).
     *
     * @return list<array{foreground: string, background: string, ratio: float, required: float}>
     */
    public function darkContrastIssues(): array
    {
        if ($this->dark === []) {
            return [];
        }

        return (new self(array_merge($this->colors, $this->dark)))->contrastIssues();
    }

    /** Dieselben Werte als Tailwind-4-Theme (`@theme`) — für Themes, die ihr CSS mit Tailwind bauen. */
    public function tailwind(): string
    {
        $lines = ['@theme {'];
        foreach ($this->colors as $name => $value) {
            $lines[] = sprintf('  --color-%s: %s;', $name, $value);
        }
        foreach ($this->fonts as $name => $font) {
            $lines[] = sprintf('  --font-%s: %s;', $name, self::familyList($font));
        }
        if ($this->space !== []) {
            $lines[] = sprintf('  --spacing: %srem;', $this->space[0] / 16);
        }
        if ($this->radius !== null) {
            $lines[] = sprintf('  --radius: %dpx;', $this->radius);
        }
        $lines[] = '}';

        return implode("\n", $lines)."\n";
    }

    /**
     * Farbpaare unter dem Mindestkontrast.
     *
     * @return list<array{foreground: string, background: string, ratio: float, required: float}>
     */
    public function contrastIssues(): array
    {
        $issues = [];
        foreach ($this->contrastPairs() as [$foreground, $background]) {
            if (! self::isColor($this->colors[$foreground]) || ! self::isColor($this->colors[$background])) {
                continue;
            }
            $ratio = self::contrast($this->colors[$foreground], $this->colors[$background]);
            if ($ratio < self::MIN_CONTRAST) {
                $issues[] = ['foreground' => $foreground, 'background' => $background, 'ratio' => round($ratio, 2), 'required' => self::MIN_CONTRAST];
            }
        }

        return $issues;
    }

    /** @return list<array{0: string, 1: string}> Vordergrund, Hintergrund */
    public function contrastPairs(): array
    {
        $pairs = [];
        if (isset($this->colors['background'])) {
            foreach (['text', 'muted', 'link'] as $foreground) {
                if (isset($this->colors[$foreground])) {
                    $pairs[] = [$foreground, 'background'];
                }
            }
        }
        foreach (array_keys($this->colors) as $name) {
            $name = (string) $name;
            $base = str_ends_with($name, '-text') ? substr($name, 0, -5) : (str_starts_with($name, 'on-') ? substr($name, 3) : null);
            if ($base !== null && $base !== '' && isset($this->colors[$base])) {
                $pairs[] = [$name, $base];
            }
        }

        return $pairs;
    }

    /** Kontrastverhältnis zweier Farben nach WCAG 2.2 (relative Leuchtdichte), 1 bis 21. */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    public static function isColor(string $value): bool
    {
        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value) === 1;
    }

    /** Schriftname ohne Zeichen, die aus dem CSS-Wert ausbrechen könnten. */
    public static function cleanFamily(string $family): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N} _-]/u', '', $family));
    }

    private static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3 || strlen($hex) === 4) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $channels = [];
        foreach ([0, 2, 4] as $i) {
            $c = hexdec(substr($hex, $i, 2)) / 255;
            $channels[] = $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /** @param  Font  $font */
    private static function familyList(array $font): string
    {
        $family = self::cleanFamily($font['family']);
        $quoted = preg_match('/^[a-z-]+$/', $family) === 1 ? $family : '"'.$family.'"';

        return $quoted.', '.self::FALLBACKS[$font['fallback']];
    }

    /** @return 'sans'|'serif'|'mono' */
    private static function fallbackOf(?string $declared, string $name, string $family): string
    {
        if ($declared === 'sans' || $declared === 'serif' || $declared === 'mono') {
            return $declared;
        }

        return str_contains(strtolower($name.' '.$family), 'mono') || str_contains(strtolower($name), 'code') ? 'mono' : 'sans';
    }

    /** @param  list<string>  $words */
    private static function after(array $words, string $keyword): ?string
    {
        $i = array_search($keyword, $words, true);

        return $i === false || ! isset($words[$i + 1]) ? null : $words[$i + 1];
    }
}
