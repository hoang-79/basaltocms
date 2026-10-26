<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts;

use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ein Mail-Code für die Anmeldung im Frontend-Konto: sechs Ziffern, nur als Prüfsumme gespeichert, je Site und E-Mail —
 * auch für Adressen ohne Konto (die erste Anmeldung legt es an). Nach fünf Fehlversuchen ist der Code verbraucht.
 *
 * @property int $id
 * @property int $site_id
 * @property string $email
 * @property string $code_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property int $attempts
 * @property string|null $ip_hash
 */
final class CustomerLoginCode extends Model
{
    use BelongsToSite;

    public const MAX_ATTEMPTS = 5;

    protected $fillable = ['site_id', 'email', 'code_hash', 'expires_at', 'used_at', 'attempts', 'ip_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime', 'attempts' => 'integer'];
    }

    public function isOpen(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture() && $this->attempts < self::MAX_ATTEMPTS;
    }

    public static function hashOf(string $code): string
    {
        return hash_hmac('sha256', 'customer-login|'.$code, (string) config('app.key'));
    }
}
