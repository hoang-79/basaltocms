<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Ein Formular einer Site (M-11) — die Felder stehen in Lava (Theme oder `forms.lava`), hier nur Betriebsdaten:
 * Webhook-Geheimnis (verschlüsselt), Spam-Zähler, letzter Eingang. Die Zeile entsteht beim ersten Gebrauch.
 *
 * @property int $id
 * @property int $site_id
 * @property string $handle
 * @property string|null $webhook_secret
 * @property int $spam_count
 * @property Carbon|null $spam_last_at
 * @property Carbon|null $last_submission_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Form extends Model
{
    use BelongsToSite;

    protected $fillable = ['site_id', 'handle', 'webhook_secret', 'spam_count', 'spam_last_at', 'last_submission_at'];

    protected $hidden = ['webhook_secret'];

    protected function casts(): array
    {
        return ['webhook_secret' => 'encrypted', 'spam_count' => 'integer', 'spam_last_at' => 'datetime', 'last_submission_at' => 'datetime'];
    }

    /** @return HasMany<FormSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }
}
