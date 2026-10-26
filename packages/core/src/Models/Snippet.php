<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Basalto\Core\Cache\PageCache;
use Basalto\Core\Database\Factories\SnippetFactory;
use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ein Baustein (M-28): eine benannte Blockgruppe der Site, in Seiten über «+ Baustein» als Verweis eingesetzt und
 * zentral änderbar — jede Änderung leert den Seiten-Cache der Site, weil sie ausgelieferte Seiten betrifft.
 *
 * @property int $id
 * @property int $site_id
 * @property string $handle
 * @property string $name
 * @property list<array{type: string, data: array<string, mixed>}> $blocks
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Snippet extends Model
{
    use BelongsToSite;

    /** @use HasFactory<SnippetFactory> */
    use HasFactory;

    protected $fillable = ['site_id', 'handle', 'name', 'blocks', 'updated_by'];

    protected function casts(): array
    {
        return ['blocks' => 'array'];
    }

    protected static function booted(): void
    {
        $invalidate = static fn (self $snippet) => app(PageCache::class)->invalidate($snippet->site_id);
        self::saved($invalidate);
        self::deleted($invalidate);
    }

    protected static function newFactory(): SnippetFactory
    {
        return SnippetFactory::new();
    }
}
