<?php

declare(strict_types=1);

namespace Basalto\Lava\Theme;

use Basalto\Lava\Diagnostics\Diagnostic;
use Basalto\Lava\Runtime\Registry;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Der Baum-Cache je Instanz (ADR-013): ein Theme wird einmal kompiliert und serialisiert im Cache-Speicher der
 * Instanz abgelegt — Schlüssel aus Quellname und Fingerabdruck, lange Lebensdauer. Ändert sich die Quelle, ändert
 * sich der Fingerabdruck, und der nächste Aufruf kompiliert neu. Nie auf die geteilte Platte (R-97).
 */
final class ThemeCache
{
    public const PREFIX = 'lava:theme:';

    public const TTL_SECONDS = 30 * 24 * 3600;

    /**
     * Stand der Übersetzung: erhöhen, wenn Lexer, Parser oder Compiler aus derselben Quelle ein anderes Ergebnis
     * machen — sonst liefert der Cache bis zu 30 Tage die alte Übersetzung (TB 89: `\n` in Zeichenketten, v1.3.15).
     */
    public const REVISION = 2;

    private readonly Repository $store;

    private readonly Registry $registry;

    private int $hits = 0;

    private int $misses = 0;

    public function __construct(?Repository $store = null, ?Registry $registry = null)
    {
        $this->store = $store ?? Cache::store();
        $this->registry = $registry ?? Registry::standard();
    }

    /**
     * Kompiliert aus dem Cache oder frisch; das Theme bekommt in beiden Fällen die übergebene Quelle (Markup-Dateien, Teile).
     *
     * @return array{theme: ?Theme, diagnostics: list<Diagnostic>}
     */
    public function compile(Source $source, string $entry = 'theme.lava'): array
    {
        $key = self::key($source, $entry, $this->registry);
        $cached = $this->read($key);
        if ($cached !== null) {
            $this->hits++;
            $theme = $cached['theme'];

            return ['theme' => $theme !== null ? $theme->withSource($source) : null, 'diagnostics' => $cached['diagnostics']];
        }
        $this->misses++;
        $compiler = new Compiler($this->registry);
        $theme = $compiler->compile($source, $entry);
        $this->store->put($key, serialize(['theme' => $theme, 'diagnostics' => $compiler->diagnostics]), self::TTL_SECONDS);

        return ['theme' => $theme, 'diagnostics' => $compiler->diagnostics];
    }

    /** Wirft den Eintrag der aktuellen Fassung weg — nach einem Schreibvorgang, wenn der Fingerabdruck gleich bleiben könnte. */
    public function forget(Source $source, string $entry = 'theme.lava'): void
    {
        $this->store->forget(self::key($source, $entry, $this->registry));
    }

    public function hits(): int
    {
        return $this->hits;
    }

    public function misses(): int
    {
        return $this->misses;
    }

    /** Der Schlüssel nennt Quelle, Einstieg, Fingerabdruck und die Feldtypen/Eigenschaften der Registrierung (sie prägen die Befunde). */
    public static function key(Source $source, string $entry, Registry $registry): string
    {
        $registryPrint = hash('xxh128', implode(',', $registry->fieldTypes()).'|'.implode(',', $registry->dottedFunctionNames()));

        return self::PREFIX.hash('xxh128', self::REVISION.'|'.$source->name().'|'.$entry.'|'.$source->fingerprint().'|'.$registryPrint);
    }

    /** @return array{theme: ?Theme, diagnostics: list<Diagnostic>}|null */
    private function read(string $key): ?array
    {
        $raw = $this->store->get($key);
        if (! is_string($raw)) {
            return null;
        }
        try {
            $data = unserialize($raw, ['allowed_classes' => true]);
        } catch (Throwable) {
            return null;
        }
        if (! is_array($data) || ! array_key_exists('theme', $data) || ! isset($data['diagnostics']) || ! is_array($data['diagnostics'])) {
            return null;
        }
        $theme = $data['theme'];
        if ($theme !== null && ! $theme instanceof Theme) {
            return null;
        }
        $diagnostics = [];
        foreach ($data['diagnostics'] as $d) {
            if ($d instanceof Diagnostic) {
                $diagnostics[] = $d;
            }
        }

        return ['theme' => $theme, 'diagnostics' => $diagnostics];
    }
}
