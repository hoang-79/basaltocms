<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Notiz zur Kundenakte (CR-004, P-11) — an der E-Mail-Adresse, damit sie SaaS-Konto, Lizenzkauf und Marktplatz
 * derselben Person verbindet.
 *
 * @property int $id
 * @property string $email
 * @property int|null $user_id
 * @property string $body
 * @property Carbon|null $created_at
 */
final class ExtranetNote extends Model
{
    protected $fillable = ['email', 'user_id', 'body'];

    /** @return BelongsTo<Model, $this> */
    public function author(): BelongsTo
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');

        return $this->belongsTo($model, 'user_id');
    }
}
