<?php

declare(strict_types=1);

namespace Basalto\Lava\Theme;

use Basalto\Lava\Diagnostics\Diagnostic;
use Basalto\Lava\Diagnostics\LavaSyntaxException;
use Basalto\Lava\Runtime\Registry;
use Basalto\Lava\Structure\Document;
use Basalto\Lava\Structure\Parser;

/**
 * Liest theme.lava samt Einbindungen aus einer Quelle, parst und prüft — liefert Theme und Befunde.
 * Ein Syntaxfehler wird zum Befund mit Zeile und Spalte, das Theme bleibt dann leer.
 * Die Registrierung (Kern plus Module) liefert dem Prüfer Feldtypen, Eigenschaften und Funktionsnamen.
 */
final class Compiler
{
    /** @var list<Diagnostic> */
    public array $diagnostics = [];

    private readonly Registry $registry;

    public function __construct(?Registry $registry = null)
    {
        $this->registry = $registry ?? Registry::standard();
    }

    public function compile(Source $source, string $entry = 'theme.lava'): ?Theme
    {
        $this->diagnostics = [];
        try {
            $document = $this->load($source, $entry, []);
        } catch (LavaSyntaxException $e) {
            $this->diagnostics[] = new Diagnostic(Diagnostic::ERROR, self::bareMessage($e), $e->lavaFile, $e->lavaLine, $e->lavaColumn);

            return null;
        }
        $theme = new Theme($document, $source);
        $this->diagnostics = (new Validator($theme, $this->registry))->run();

        return $theme;
    }

    public function registry(): Registry
    {
        return $this->registry;
    }

    /** Die Meldung einer Syntax-Ausnahme ohne den Ortszusatz «(datei, Zeile n, Spalte m)». */
    public static function bareMessage(LavaSyntaxException $e): string
    {
        return preg_replace('/ \([^()]*, Zeile \d+, Spalte \d+\)$/', '', $e->getMessage()) ?? $e->getMessage();
    }

    /** @param  list<string>  $seen */
    private function load(Source $source, string $path, array $seen): Document
    {
        $seen[] = $path;
        $document = Parser::parse($source->read($path), $path);
        foreach ($document->includes as $include) {
            if (in_array($include['file'], $seen, true)) {
                throw new LavaSyntaxException(sprintf('Zirkuläre Einbindung von «%s»', $include['file']), $path, $include['line'], $include['column']);
            }
            if (! FileSource::isInside($include['file']) || ! $source->has($include['file'])) {
                throw new LavaSyntaxException(sprintf('Eingebundene Datei «%s» fehlt', $include['file']), $path, $include['line'], $include['column']);
            }
            $document->merge($this->load($source, $include['file'], $seen));
        }

        return $document;
    }
}
