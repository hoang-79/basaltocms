<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Eine hochgeladene Datei im Objektspeicher, je Site (K-08). `variants` hält die erzeugten Bildvarianten
 * (Name → Pfad der WebP-Datei, Breite, Höhe, AVIF-Pfad, höhere Dichten); `alt` den Alt-Text je Sprache.
 *
 * @phpstan-type VariantFile array{path: string, width: int, height: int, avif: string|null}
 * @phpstan-type Variant array{path: string, width: int, height: int, avif: string|null, densities?: array<string, VariantFile>}
 *
 * @property int $id
 * @property int $site_id
 * @property int|null $folder_id
 * @property string $disk
 * @property string $path
 * @property string $name
 * @property string|null $title
 * @property string|null $credit
 * @property array<string, string>|null $alt
 * @property string $mime
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property float $focus_x
 * @property float $focus_y
 * @property array<string, Variant>|null $variants
 * @property Carbon|null $variants_generated_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
final class Media extends Model
{
    use BelongsToSite;
    use SoftDeletes;

    protected $fillable = ['site_id', 'folder_id', 'disk', 'path', 'name', 'title', 'credit', 'alt', 'mime', 'size', 'width', 'height', 'focus_x', 'focus_y', 'variants', 'variants_generated_at', 'created_by'];

    protected function casts(): array
    {
        return ['variants' => 'array', 'alt' => 'array', 'variants_generated_at' => 'datetime', 'focus_x' => 'float', 'focus_y' => 'float'];
    }

    /** @return BelongsTo<MediaFolder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class);
    }

    /** @return HasMany<MediaUsage, $this> */
    public function usages(): HasMany
    {
        return $this->hasMany(MediaUsage::class);
    }

    /** @return BelongsTo<Model, $this> */
    public function uploader(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');

        return $this->belongsTo($userModel, 'created_by');
    }

    public function storage(): Filesystem
    {
        return Storage::disk($this->disk);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    /** Vektorgrafiken bekommen keine Varianten — sie skalieren selbst. */
    public function isRasterImage(): bool
    {
        return $this->isImage() && $this->mime !== 'image/svg+xml';
    }

    /** Alt-Text in einer Sprache — leer zählt als fehlend. */
    public function altFor(string $locale): ?string
    {
        $alt = $this->alt[$locale] ?? null;

        return is_string($alt) && trim($alt) !== '' ? trim($alt) : null;
    }

    /**
     * Sprachen ohne Alt-Text (K-10) — nur bei Bildern; Dokumente brauchen keinen.
     *
     * @param  list<string>  $locales
     * @return list<string>
     */
    public function missingAlt(array $locales): array
    {
        if (! $this->isImage()) {
            return [];
        }

        return array_values(array_filter($locales, fn (string $locale): bool => $this->altFor($locale) === null));
    }

    /** Stand der Varianten für die Oberfläche: fertig, unterwegs oder keine (Dokumente, Vektorgrafiken). */
    public function variantsState(): string
    {
        if (! $this->isRasterImage()) {
            return 'none';
        }

        return $this->variants_generated_at !== null && $this->variants !== null ? 'ready' : 'pending';
    }

    /** Pfad ohne Endung — davon leiten sich die Varianten ab. */
    public function basePath(): string
    {
        return preg_replace('/\.[A-Za-z0-9]+$/', '', $this->path) ?? $this->path;
    }

    /** @return list<string> alle Dateien der Varianten (WebP, AVIF, höhere Dichten) */
    public function variantPaths(): array
    {
        $paths = [];
        foreach ($this->variants ?? [] as $variant) {
            $paths[] = $variant['path'];
            if (($variant['avif'] ?? null) !== null) {
                $paths[] = $variant['avif'];
            }
            foreach ($variant['densities'] ?? [] as $file) {
                $paths[] = $file['path'];
                if (($file['avif'] ?? null) !== null) {
                    $paths[] = $file['avif'];
                }
            }
        }

        return $paths;
    }

    /** Pfad einer Variante in einem Format und einer Dichte — null, wenn es sie nicht gibt. */
    public function variantPath(string $variant, string $format = 'webp', int $density = 1): ?string
    {
        $v = $this->variants[$variant] ?? null;
        if ($v === null) {
            return null;
        }
        if ($density > 1) {
            $densities = $v['densities'] ?? [];
            if (! array_key_exists((string) $density, $densities)) {
                return null;
            }
            $file = $densities[(string) $density];
        } else {
            $file = $v;
        }

        return $format === 'avif' ? ($file['avif'] ?? null) : $file['path'];
    }

    /** URL des Originals oder einer Variante — temporär signiert, wenn die Disk das kann (private Buckets), sonst über die Auslieferungsroute. */
    public function url(?string $variant = null, string $format = 'webp', int $density = 1): string
    {
        $path = $variant !== null ? ($this->variantPath($variant, $format, $density) ?? $this->path) : $this->path;
        $storage = $this->storage();
        if (method_exists($storage, 'providesTemporaryUrls') && $storage->providesTemporaryUrls()) {
            $url = $storage->temporaryUrl($path, now()->addMinutes(30));
            // Lokal signiert MinIO mit http://localhost — auf einer HTTPS-Seite wäre das gemischter Inhalt; dann liefert die Anwendung selbst aus.
            if (! (str_starts_with($url, 'http://') && request()->isSecure())) {
                return $url;
            }
        }

        return route('basalto.media.show', ['media' => $this->id, 'variant' => $variant, 'format' => $format === 'webp' ? null : $format, 'density' => $density > 1 ? $density : null]);
    }
}
