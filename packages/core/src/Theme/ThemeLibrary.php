<?php

declare(strict_types=1);

namespace Basalto\Core\Theme;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Models\Site;
use Basalto\Core\Models\SiteTheme;
use Basalto\Core\Models\ThemeVersion;
use Basalto\Lava\Theme\DatabaseSource;
use Basalto\Lava\Theme\FileSource;
use Basalto\Lava\Theme\LavaSource;
use Basalto\Lava\Theme\Source;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Die Theme-Bibliothek einer Site (TB 22): eigene Themes hinzufügen (Kopie, Paket, Marktplatz), entfernen, mitgelieferte
 * Theme-Ordner ausblenden. Ein eigenes Theme hat seine Textdateien in `lava_sources`, Bilder und Schriften auf der
 * Standard-Disk — so geht es auch im SaaS-Betrieb und auf Laravel Cloud, wo der Ordner `themes/` allen Sites gehört
 * und hochgeladene Dateien den nächsten Deploy nicht überleben.
 */
final class ThemeLibrary
{
    /** Name eines Themes: Kleinbuchstaben, Ziffern, Bindestrich — wie ein Ordnername. */
    public const NAME = '/^[a-z0-9][a-z0-9-]{0,59}$/';

    /** Bilder und Schriften: Endungen, die als Datei (nicht als Text) gespeichert werden. */
    public const BINARY = ['woff2', 'woff', 'png', 'jpg', 'jpeg', 'webp', 'avif', 'ico'];

    public const MAX_ASSET_BYTES = 5 * 1024 * 1024;

    public const MAX_TOTAL_BYTES = 40 * 1024 * 1024;

    public const MAX_FILES = 800;

    public function __construct(private readonly Recorder $recorder) {}

    /** @return Collection<int, SiteTheme> */
    public function all(Site $site): Collection
    {
        return SiteTheme::query()->where('site_id', $site->id)->orderBy('name')->get();
    }

    public function find(Site $site, string $name): ?SiteTheme
    {
        return SiteTheme::query()->where('site_id', $site->id)->where('name', $name)->first();
    }

    /** Das eigene Theme, das die Site gerade zeigt — null, wenn sie ein Theme aus dem Ordner nutzt. */
    public function active(Site $site): ?SiteTheme
    {
        return $this->find($site, self::activeName($site));
    }

    public static function activeName(Site $site): string
    {
        return $site->theme ?? (string) config('basalto.default_theme', 'nguyen-development');
    }

    public static function hasFolder(string $name): bool
    {
        return preg_match(self::NAME, $name) === 1 && is_file(ThemeCatalog::path().'/'.$name.'/theme.lava');
    }

    /** Gibt es das Theme für die Site — eigenes oder Ordner? */
    public function exists(Site $site, string $name): bool
    {
        return $this->find($site, $name) !== null || self::hasFolder($name);
    }

    public function textSource(SiteTheme $theme): DatabaseSource
    {
        return new DatabaseSource($theme->site_id, $theme->scope(), $theme->name);
    }

    /**
     * Die veröffentlichten Textdateien eines eigenen Themes.
     *
     * @return array<string, string>
     */
    public function textFiles(SiteTheme $theme): array
    {
        $source = $this->textSource($theme);
        $files = [];
        foreach ($source->paths() as $path) {
            $files[$path] = $source->read($path);
        }
        ksort($files);

        return $files;
    }

    /**
     * Ausgeblendete Theme-Ordner der Site — sie erscheinen nicht mehr in der Liste, bleiben aber installiert.
     *
     * @return list<string>
     */
    public function hidden(Site $site): array
    {
        $hidden = $site->setting('themes.hidden', []);

        return is_array($hidden) ? array_values(array_filter($hidden, 'is_string')) : [];
    }

    public function setHidden(Site $site, string $name, bool $hidden, Authenticatable $user): void
    {
        if (! self::hasFolder($name)) {
            throw new ThemeEditorException(__('Ausblenden lassen sich nur mitgelieferte Themes aus dem Ordner.'), status: 404);
        }
        if ($hidden && self::activeName($site) === $name) {
            throw new ThemeEditorException(__('Das aktive Theme lässt sich nicht ausblenden.'));
        }
        $list = array_values(array_unique($hidden ? [...$this->hidden($site), $name] : array_diff($this->hidden($site), [$name])));
        sort($list);
        $settings = $site->settings ?? [];
        $settings['themes']['hidden'] = $list;
        $site->forceFill(['settings' => $settings])->save();
        $this->recorder->record($hidden ? 'theme.hide' : 'theme.show', $site, null, ['theme' => $name], $site, $user);
    }

    /** Ein freier Name: `$base`, sonst `$base-2`, `$base-3` … — weder eigenes Theme noch Ordner. */
    public function uniqueName(Site $site, string $base): string
    {
        $base = Str::limit(trim(Str::slug($base), '-'), 54, '') ?: 'theme';
        $name = $base;
        for ($i = 2; $this->exists($site, $name); $i++) {
            $name = $base.'-'.$i;
        }

        return $name;
    }

    /**
     * Das aktive Theme aus dem Ordner als eigenes Theme gleichen Namens übernehmen (L-17) — es ersetzt den Ordner für
     * diese Site; Bilder und Schriften kommen weiter aus dem Ordner.
     */
    public function adoptFolder(Site $site, Authenticatable $user): SiteTheme
    {
        $name = self::activeName($site);
        if ($this->find($site, $name) !== null) {
            throw new ThemeEditorException(__('Das Theme liegt schon in der Datenbank.'), status: 409);
        }
        if (! self::hasFolder($name)) {
            throw new ThemeEditorException(__('Theme «:name» gibt es nicht im Ordner.', ['name' => $name]), status: 404);
        }
        $text = self::split(new FileSource(ThemeCatalog::path().'/'.$name))['text'];
        $meta = self::meta($text);

        return $this->create($site, $name, $text, [], $user, [
            'label' => $meta['label'], 'description' => $meta['description'], 'version' => $meta['version'], 'origin' => 'ordner', 'origin_name' => $name,
        ], __('Aus dem Theme-Ordner übernommen'), 'theme.adopt');
    }

    /**
     * Für `basalto:lava-import --scope=theme`: Dateien in das eigene Theme ziehen, das die Site zeigt — fehlt es, wird
     * es unter dem Namen ihres Themes angelegt. Vorhandene Dateien werden ersetzt.
     */
    public function importSource(Site $site, Source $source): int
    {
        $name = self::activeName($site);
        $theme = $this->find($site, $name) ?? SiteTheme::query()->create(['site_id' => $site->id, 'name' => $name, 'origin' => 'ordner', 'origin_name' => $name]);

        return DatabaseSource::importFrom($source, $site->id, $theme->scope());
    }

    /** Eine Kopie eines Themes (eigenes oder Ordner) unter neuem Namen anlegen — mit Bildern und Schriften. */
    public function copy(Site $site, string $from, string $name, ?string $label, Authenticatable $user): SiteTheme
    {
        $own = $this->find($site, $from);
        if ($own === null && ! self::hasFolder($from)) {
            throw new ThemeEditorException(__('Theme «:name» gibt es nicht.', ['name' => $from]), status: 404);
        }
        $this->assertFreeName($site, $name);
        $files = ['text' => [], 'binary' => []];
        // Ein übernommenes Theme holt Binäres aus dem Ordner gleichen Namens — das geht mit in die Kopie
        if (self::hasFolder($from)) {
            $files = self::split(new FileSource(ThemeCatalog::path().'/'.$from));
        }
        if ($own !== null) {
            $files['text'] = $this->textFiles($own);
            $store = new AssetStore($own);
            foreach ($store->paths() as $path) {
                $files['binary'][$path] = $store->read($path);
            }
        }
        $meta = self::meta($files['text']);

        return $this->create($site, $name, $files['text'], $files['binary'], $user, [
            'label' => $label !== null && trim($label) !== '' ? trim($label) : __('Kopie von :name', ['name' => $own->label ?? $meta['label'] ?? $from]),
            'description' => $own->description ?? $meta['description'], 'version' => $own->version ?? $meta['version'], 'origin' => 'kopie', 'origin_name' => $from,
        ], __('Kopie von «:name»', ['name' => $from]), 'theme.copy');
    }

    /**
     * Ein Theme-Paket als neues eigenes Theme: geprüft wie beim Import im Editor (Manifest, Prüfsummen, Signatur, falls
     * vorhanden), der Name aus dem Manifest — ist er vergeben, mit Zähler.
     */
    public function importPackage(Site $site, string $zip, Authenticatable $user, string $origin = 'paket', ?string $name = null): SiteTheme
    {
        $verified = self::verifyPackage($zip);
        $manifest = $verified['manifest'];
        $files = self::packageFiles($zip);
        if (! isset($files['text']['theme.lava'])) {
            throw new ThemeEditorException(__('Im Paket fehlt theme.lava.'));
        }
        $name = $name !== null ? $name : $this->uniqueName($site, $manifest['name']);
        $this->assertFreeName($site, $name);
        $meta = self::meta($files['text']);

        return $this->create($site, $name, $files['text'], $files['binary'], $user, [
            'label' => $meta['label'] ?? $manifest['name'], 'description' => $meta['description'], 'version' => $manifest['version'], 'origin' => $origin,
            'origin_name' => $manifest['name'], 'signed' => $verified['signed'],
        ], $origin === 'marktplatz' ? __('Aus dem Marktplatz') : __('Aus Paket «:name»', ['name' => $manifest['name']]), 'theme.add');
    }

    /** Ein eigenes Theme entfernen — nicht das aktive, ausser ein Ordner gleichen Namens springt ein. */
    public function remove(SiteTheme $theme, Authenticatable $user): void
    {
        $site = $theme->site;
        if (self::activeName($site) === $theme->name && ! self::hasFolder($theme->name)) {
            throw new ThemeEditorException(__('Theme «:name» ist aktiv — zuerst ein anderes Theme wählen.', ['name' => $theme->label ?? $theme->name]));
        }
        DB::transaction(function () use ($theme, $site, $user): void {
            $files = LavaSource::query()->where('site_id', $theme->site_id)->where('scope', $theme->scope())->delete();
            $theme->delete();
            $this->recorder->record('theme.remove', $site, ['theme' => $theme->name, 'dateien' => $files, 'bilder' => count($theme->assets ?? [])], null, $site, $user);
        });
        Storage::disk()->deleteDirectory($theme->assetDirectory());
    }

    /** Bild oder Schrift ablegen (sofort wirksam — Binäres hat keine Entwürfe). */
    public function storeAsset(SiteTheme $theme, string $path, string $content, Authenticatable $user): void
    {
        $path = trim($path, '/');
        if (! self::isBinaryPath($path)) {
            throw new ThemeEditorException(__('«:path» ist kein Pfad für Bilder oder Schriften (unter assets/, :types).', ['path' => $path, 'types' => implode(', ', self::BINARY)]));
        }
        if (strlen($content) > self::MAX_ASSET_BYTES) {
            throw new ThemeEditorException(__('Die Datei ist zu gross (höchstens :mb MB).', ['mb' => self::MAX_ASSET_BYTES / 1048576]));
        }
        $assets = $theme->assets ?? [];
        $total = array_sum(array_column($assets, 'size')) - ($assets[$path]['size'] ?? 0) + strlen($content);
        if ($total > self::MAX_TOTAL_BYTES) {
            throw new ThemeEditorException(__('Zusammen wären die Bilder und Schriften des Themes zu gross (höchstens :mb MB).', ['mb' => self::MAX_TOTAL_BYTES / 1048576]));
        }
        Storage::disk()->put($theme->assetDirectory().'/'.$path, $content);
        $assets[$path] = ['hash' => substr(hash('xxh128', $content), 0, 12), 'size' => strlen($content)];
        ksort($assets);
        $theme->forceFill(['assets' => $assets])->save();
        $this->recorder->record('theme.asset', $theme->site, null, ['theme' => $theme->name, 'pfad' => $path], $theme->site, $user);
    }

    public function deleteAsset(SiteTheme $theme, string $path, Authenticatable $user): void
    {
        $assets = $theme->assets ?? [];
        if (! isset($assets[$path])) {
            throw new ThemeEditorException(__('Die Datei «:path» gibt es nicht.', ['path' => $path]), status: 404);
        }
        unset($assets[$path]);
        $theme->forceFill(['assets' => $assets])->save();
        Storage::disk()->delete($theme->assetDirectory().'/'.$path);
        $this->recorder->record('theme.asset', $theme->site, ['theme' => $theme->name, 'pfad' => $path], null, $theme->site, $user);
    }

    /** Ein eigenes Theme als Paket — Textdateien und Bilder (unsigniert; signieren kann nur der Hersteller). */
    public function export(SiteTheme $theme, string $outputDir): string
    {
        $files = $this->textFiles($theme);
        $store = new AssetStore($theme);
        foreach ($store->paths() as $path) {
            $files[$path] = $store->read($path);
        }

        return (new ThemePackage)->packSource(new MemorySource($files, $theme->name), $theme->name, $outputDir)['path'];
    }

    public function snapshot(SiteTheme $theme, ?string $note, Authenticatable|int|null $submittedBy, Authenticatable $approvedBy): ThemeVersion
    {
        $number = (int) ThemeVersion::query()->where('site_theme_id', $theme->id)->max('number') + 1;

        return ThemeVersion::query()->create([
            'site_id' => $theme->site_id, 'site_theme_id' => $theme->id, 'number' => $number, 'files' => $this->textFiles($theme), 'note' => $note === null ? null : mb_substr($note, 0, 500),
            'submitted_by' => $submittedBy instanceof Authenticatable ? (int) $submittedBy->getAuthIdentifier() : $submittedBy,
            'approved_by' => (int) $approvedBy->getAuthIdentifier(), 'published_at' => now(),
        ]);
    }

    public static function isBinaryPath(string $path): bool
    {
        return ThemeAssets::allowed($path) && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::BINARY, true);
    }

    /**
     * Ein Paket prüfen — Signatur mit dem Produkt- oder dem Marktplatz-Schlüssel, falls es eine hat.
     *
     * @return array{signed: bool, manifest: array{format: int, name: string, version: string, files: array<string, string>}}
     */
    public static function verifyPackage(string $zip): array
    {
        $package = new ThemePackage;
        $verified = $package->verify($zip);
        if ($verified['signed']) {
            $product = $package->verify($zip, (string) config('basalto.license.public_key'));
            // TB 11 Scheibe 2 (M-20): oder mit dem Marktplatz-Schlüssel (Themes aus dem Marktplatz von basaltocms.com)
            $marketplace = trim((string) config('basalto.marketplace.public_key'));
            $verified = $product['ok'] || $marketplace === '' ? $product : $package->verify($zip, $marketplace);
        }
        if (! $verified['ok'] || $verified['manifest'] === null) {
            throw new ThemeEditorException(__('Das Paket ist ungültig: :errors', ['errors' => implode(' · ', array_slice($verified['errors'], 0, 3))]));
        }

        return ['signed' => $verified['signed'], 'manifest' => $verified['manifest']];
    }

    /**
     * Die Dateien eines geprüften Pakets, getrennt in Text und Bilder/Schriften — mit Obergrenzen gegen Zip-Bomben.
     *
     * @return array{text: array<string, string>, binary: array<string, string>}
     */
    public static function packageFiles(string $zip): array
    {
        $archive = new ZipArchive;
        if ($archive->open($zip, ZipArchive::RDONLY) !== true) {
            throw new ThemeEditorException(__('Kein lesbares ZIP.'));
        }
        $total = 0;
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $total += (int) ($archive->statIndex($i)['size'] ?? 0);
        }
        $count = $archive->numFiles;
        $archive->close();
        if ($count > self::MAX_FILES || $total > self::MAX_TOTAL_BYTES + 10 * 1024 * 1024) {
            throw new ThemeEditorException(__('Das Paket ist zu gross (höchstens :files Dateien und :mb MB entpackt).', ['files' => self::MAX_FILES, 'mb' => self::MAX_TOTAL_BYTES / 1048576 + 10]));
        }

        return self::split(new MemorySource((new ThemePackage)->files($zip)));
    }

    /**
     * Textdateien (bearbeitbar) und Bilder/Schriften einer Quelle; anderes fällt weg.
     *
     * @return array{text: array<string, string>, binary: array<string, string>}
     */
    public static function split(Source $source): array
    {
        $out = ['text' => [], 'binary' => []];
        foreach ($source->paths() as $path) {
            if (ThemeEditor::isEditablePath($path)) {
                $content = $source->read($path);
                if (strlen($content) <= ThemeEditor::MAX_BYTES && mb_check_encoding($content, 'UTF-8')) {
                    $out['text'][$path] = $content;
                }
            } elseif (self::isBinaryPath($path)) {
                $content = $source->read($path);
                if (strlen($content) <= self::MAX_ASSET_BYTES) {
                    $out['binary'][$path] = $content;
                }
            }
        }
        ksort($out['text']);
        ksort($out['binary']);

        return $out;
    }

    /**
     * Angaben aus `theme.json`.
     *
     * @param  array<string, string>  $text
     * @return array{label: string|null, description: string|null, version: string|null}
     */
    public static function meta(array $text): array
    {
        $json = isset($text['theme.json']) ? json_decode($text['theme.json'], true) : null;
        $json = is_array($json) ? $json : [];
        $string = static fn (string $key, int $max): ?string => is_string($json[$key] ?? null) && trim($json[$key]) !== '' ? mb_substr(trim($json[$key]), 0, $max) : null;

        return ['label' => $string('label', 120), 'description' => $string('description', 500), 'version' => $string('version', 40)];
    }

    private function assertFreeName(Site $site, string $name): void
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new ThemeEditorException(__('Der Name darf nur Kleinbuchstaben, Ziffern und Bindestriche enthalten (z. B. «mein-theme»).'));
        }
        if ($this->exists($site, $name)) {
            throw new ThemeEditorException(__('Ein Theme «:name» gibt es schon — bitte einen anderen Namen wählen.', ['name' => $name]), status: 409);
        }
    }

    /**
     * @param  array<string, string>  $text
     * @param  array<string, string>  $binary
     * @param  array{label: string|null, description: string|null, version: string|null, origin: string, origin_name: string|null, signed?: bool}  $attributes
     */
    private function create(Site $site, string $name, array $text, array $binary, Authenticatable $user, array $attributes, string $note, string $action): SiteTheme
    {
        $total = array_sum(array_map('strlen', $binary));
        if ($total > self::MAX_TOTAL_BYTES) {
            throw new ThemeEditorException(__('Zusammen wären die Bilder und Schriften des Themes zu gross (höchstens :mb MB).', ['mb' => self::MAX_TOTAL_BYTES / 1048576]));
        }
        $theme = DB::transaction(function () use ($site, $name, $text, $user, $attributes): SiteTheme {
            $theme = SiteTheme::query()->create(['site_id' => $site->id, 'name' => $name, 'created_by' => (int) $user->getAuthIdentifier()] + $attributes);
            DatabaseSource::importFrom(new MemorySource($text, $name), $site->id, $theme->scope());

            return $theme;
        });
        $assets = [];
        foreach ($binary as $path => $content) {
            Storage::disk()->put($theme->assetDirectory().'/'.$path, $content);
            $assets[$path] = ['hash' => substr(hash('xxh128', $content), 0, 12), 'size' => strlen($content)];
        }
        if ($assets !== []) {
            $theme->forceFill(['assets' => $assets])->save();
        }
        $version = $this->snapshot($theme, $note, $user, $user);
        $this->recorder->record($action, $site, null, ['theme' => $name, 'herkunft' => $attributes['origin'], 'von' => $attributes['origin_name'], 'dateien' => count($text), 'bilder' => count($assets), 'fassung' => $version->number], $site, $user);

        return $theme;
    }
}
