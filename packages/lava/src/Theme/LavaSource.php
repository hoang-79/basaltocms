<?php

declare(strict_types=1);

namespace Basalto\Lava\Theme;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Eine Theme-Datei als Datensatz: site_id + scope + path + source. So bearbeiten SaaS-Sites ihr Theme im Backend,
 * ohne dass auf Laravel Cloud etwas auf die flüchtige Platte geschrieben wird. Geltungsbereich «theme» ist das
 * Theme der Site, «site» sind site-eigene Quellen aus dem Backend (Typen, TB 01 Scheibe 3).
 *
 * @property int $id
 * @property int $site_id
 * @property string $scope
 * @property string $path
 * @property string $source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class LavaSource extends Model
{
    public const SCOPE_THEME = 'theme';

    public const SCOPE_SITE = 'site';

    /** Präfix der eigenen Themes einer Site (TB 22): `theme:<id>` — je Theme der Bibliothek ein Geltungsbereich. */
    public const THEME_PREFIX = 'theme:';

    protected $table = 'lava_sources';

    protected $fillable = ['site_id', 'scope', 'path', 'source'];

    public static function themeScope(int $id): string
    {
        return self::THEME_PREFIX.$id;
    }
}
