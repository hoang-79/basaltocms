<?php

declare(strict_types=1);

namespace Basalto\Modules\Ai\Models;

use Basalto\Core\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eine Nutzung des KI-Moduls (M-24): wer, wann, welche Funktion, an welchem Inhalt oder Medium, mit welchem Anbieter
 * und Modell, wie viele Tokens, Erfolg oder Fehler (als Kategorie) — und ob der Vorschlag übernommen wurde. Bewusst
 * ohne Text, Prompt, Antwort und Schlüssel.
 *
 * @property int $id
 * @property int $site_id
 * @property int|null $user_id
 * @property string $feature
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $locale
 * @property string $provider
 * @property string|null $model
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property string $status
 * @property string|null $error
 * @property Carbon|null $applied_at
 * @property Carbon|null $created_at
 */
final class AiUsage extends Model
{
    public const UPDATED_AT = null;

    public const OK = 'ok';

    public const FAILED = 'error';

    protected $table = 'ai_usages';

    protected $fillable = ['site_id', 'user_id', 'feature', 'subject_type', 'subject_id', 'locale', 'provider', 'model', 'input_tokens', 'output_tokens', 'status', 'error', 'applied_at'];

    protected function casts(): array
    {
        return ['applied_at' => 'datetime', 'input_tokens' => 'integer', 'output_tokens' => 'integer', 'subject_id' => 'integer'];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
