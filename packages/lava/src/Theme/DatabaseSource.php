<?php

declare(strict_types=1);

namespace Basalto\Lava\Theme;

use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/** Ein Theme aus der Tabelle lava_sources, je Site und Geltungsbereich («theme:<id>» je eigenes Theme oder «site»). */
final class DatabaseSource implements Source
{
    /** @var array<string, string>|null */
    private ?array $cache = null;

    /** @param  string|null  $label  Anzeigename (z. B. das Handle der Site); sonst «site-<id>» */
    public function __construct(private readonly int $siteId, private readonly string $scope = LavaSource::SCOPE_THEME, private readonly ?string $label = null) {}

    public function name(): string
    {
        $name = $this->label ?? sprintf('site-%d', $this->siteId);

        return $this->scope === LavaSource::SCOPE_THEME || str_starts_with($this->scope, LavaSource::THEME_PREFIX) ? $name : $name.'/'.$this->scope;
    }

    public function siteId(): int
    {
        return $this->siteId;
    }

    public function scope(): string
    {
        return $this->scope;
    }

    public function has(string $path): bool
    {
        return isset($this->all()[$path]);
    }

    public function read(string $path): string
    {
        return $this->all()[$path] ?? throw new RuntimeException(sprintf('Theme-Datei fehlt in der Datenbank: %s (Site %d, %s)', $path, $this->siteId, $this->scope));
    }

    public function paths(): array
    {
        return array_keys($this->all());
    }

    /**
     * Anzahl, jüngste Änderung, höchste Kennung und Gesamtlänge genügen: jeder Schreibvorgang setzt updated_at,
     * jedes Löschen senkt die Anzahl — und zwei Änderungen in derselben Sekunde unterscheiden sich in der Länge.
     */
    public function fingerprint(): string
    {
        $row = $this->query()->selectRaw('count(*) as n, max(updated_at) as latest, max(id) as last_id, sum(length(source)) as bytes')->first();

        return hash('xxh128', sprintf('%d|%s|%d|%s|%d|%d', $this->siteId, $this->scope, (int) ($row->n ?? 0), (string) ($row->latest ?? ''), (int) ($row->last_id ?? 0), (int) ($row->bytes ?? 0)));
    }

    /** Vergisst den gelesenen Stand — nach einem Schreibvorgang in derselben Anfrage. */
    public function refresh(): void
    {
        $this->cache = null;
    }

    /** @return array<string, string> */
    private function all(): array
    {
        return $this->cache ??= $this->query()->orderBy('path')->pluck('source', 'path')->all();
    }

    /** @return Builder<LavaSource> */
    private function query(): Builder
    {
        return LavaSource::query()->where('site_id', $this->siteId)->where('scope', $this->scope);
    }

    /** Kopiert ein Theme (Dateien oder Datenbank) in die Datenbank, vorhandene Datensätze werden ersetzt. */
    public static function importFrom(Source $source, int $siteId, string $scope = LavaSource::SCOPE_THEME): int
    {
        $n = 0;
        foreach ($source->paths() as $path) {
            $content = $source->read($path);
            // Binäres (Schriften, Bilder unter assets/) bleibt im Theme-Ordner — die Spalte ist Text (PostgreSQL lehnt
            // ungültiges UTF-8 ab); ausgeliefert wird es von dort (Basalto\Core\Theme\ThemeAssets)
            if (! mb_check_encoding($content, 'UTF-8')) {
                continue;
            }
            LavaSource::query()->updateOrCreate(['site_id' => $siteId, 'scope' => $scope, 'path' => $path], ['source' => $content]);
            $n++;
        }

        return $n;
    }
}
