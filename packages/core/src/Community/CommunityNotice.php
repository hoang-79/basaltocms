<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ein Hinweis in der Community (CR-006 FO-17), Vorbild WoltLab «Hinweise»: Text je Sprache, Ton, Bedingungen
 * (Gruppen, Bereich, angemeldet oder nicht), Zeitraum, ausblendbar.
 *
 * @property int $id
 * @property int $site_id
 * @property array<string, string> $text
 * @property string $tone
 * @property array{groups?: list<int>, area?: string, audience?: string, testdaten?: bool}|null $conditions (testdaten: angelegt von basalto:testdaten)
 * @property bool $dismissible
 * @property bool $is_active
 * @property int $position
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 */
final class CommunityNotice extends Model
{
    use BelongsToSite;

    public const TONES = ['info', 'warnung', 'erfolg'];

    protected $table = 'community_notices';

    protected $fillable = ['site_id', 'text', 'tone', 'conditions', 'dismissible', 'is_active', 'position', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return ['text' => 'array', 'conditions' => 'array', 'dismissible' => 'boolean', 'is_active' => 'boolean', 'position' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function textIn(string $lang, string $fallback): string
    {
        $text = $this->text;

        return (string) ($text[$lang] ?? $text[$fallback] ?? (array_values($text)[0] ?? ''));
    }
}
