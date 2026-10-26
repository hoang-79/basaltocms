<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Fassung eines Themes aus der Datenbank (L-17): Schnappschuss aller Dateien bei jeder Veröffentlichung — Grundlage
 * fürs Wiederherstellen.
 *
 * @property int $id
 * @property int $site_id
 * @property int|null $site_theme_id
 * @property int $number
 * @property array<string, string> $files
 * @property string|null $note
 * @property int|null $submitted_by
 * @property int|null $approved_by
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 */
final class ThemeVersion extends Model
{
    protected $fillable = ['site_id', 'site_theme_id', 'number', 'files', 'note', 'submitted_by', 'approved_by', 'published_at'];

    protected function casts(): array
    {
        return ['files' => 'array', 'published_at' => 'datetime'];
    }
}
