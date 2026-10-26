<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Basalto\Lava\Theme\LavaSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ein eigenes Theme der Site (TB 22, Theme-Bibliothek): Textdateien in `lava_sources` (`scope = theme:<id>`), Bilder
 * und Schriften auf der Standard-Disk, Entwürfe und Fassungen je Theme. Heisst es wie ein Theme-Ordner, ersetzt es
 * diesen für die Site; Binäres, das ihm fehlt, kommt dann aus dem Ordner.
 *
 * @property int $id
 * @property int $site_id
 * @property string $name
 * @property string|null $label
 * @property string|null $description
 * @property string|null $version
 * @property string $origin
 * @property string|null $origin_name
 * @property bool $signed
 * @property array<string, array{hash: string, size: int}>|null $assets
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Site $site
 */
final class SiteTheme extends Model
{
    /** Herkunft: übernommen aus dem Ordner gleichen Namens, Kopie, hochgeladenes Paket, Marktplatz. */
    public const ORIGINS = ['ordner', 'kopie', 'paket', 'marktplatz'];

    protected $fillable = ['site_id', 'name', 'label', 'description', 'version', 'origin', 'origin_name', 'signed', 'assets', 'created_by'];

    protected function casts(): array
    {
        return ['signed' => 'boolean', 'assets' => 'array'];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Geltungsbereich der Textdateien in `lava_sources`. */
    public function scope(): string
    {
        return LavaSource::themeScope($this->id);
    }

    /** Ordner auf der Standard-Disk für Bilder und Schriften. */
    public function assetDirectory(): string
    {
        return 'theme-assets/'.$this->site_id.'/'.$this->id;
    }
}
