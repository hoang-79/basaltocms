<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * TB 13 Scheibe 4 (M-25): Verknüpfung eines Backend-Benutzers mit seiner Kennung beim SSO-Anbieter — angelegt bei der
 * ersten Anmeldung über die bestätigte E-Mail, danach massgeblich (eine geänderte E-Mail beim Anbieter bricht sie nicht).
 *
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $issuer
 * @property string $subject
 * @property string $email
 * @property ?CarbonImmutable $last_login_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
final class SsoIdentity extends Model
{
    protected $fillable = ['user_id', 'provider', 'issuer', 'subject', 'email', 'last_login_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['last_login_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
