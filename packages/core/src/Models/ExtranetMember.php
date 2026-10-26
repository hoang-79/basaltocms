<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine Person im Team des Herstellers (CR-004, P-09) mit ihrer Rolle im Extranet — Schlüssel aus der Tabelle `roles`
 * bzw. den Vorlagen. Besitzer und Verwaltung des Installationskontos brauchen keine Zeile; sie dürfen alles.
 *
 * @property int $id
 * @property int $user_id
 * @property string $role
 * @property int|null $added_by
 */
final class ExtranetMember extends Model
{
    protected $fillable = ['user_id', 'role', 'added_by'];

    /** @return BelongsTo<Model, $this> */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');

        return $this->belongsTo($model, 'user_id');
    }
}
