<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Ein Hinweis an ein Konto, der genau einmal hinausgeht (TB 12 Scheibe 1): Art (`trial.ending`, `trial.ended`, später
 * Rechnung, Kündigung …) und Bezug (z. B. das Enddatum der Testphase — wird sie verlängert, gilt ein neuer Bezug).
 * Der eindeutige Schlüssel über Konto, Art und Bezug macht Scheduler-Läufe idempotent, auch parallel.
 *
 * @property int $id
 * @property int $account_id
 * @property string $kind
 * @property string $reference
 * @property Carbon $sent_at
 */
final class AccountNotice extends Model
{
    protected $fillable = ['account_id', 'kind', 'reference', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Den Hinweis senden, wenn er für Konto, Art und Bezug noch nicht hinausging — sonst nichts tun. Scheitert der
     * Versand, wird die Markierung zurückgenommen, damit der nächste Lauf es wieder versucht.
     *
     * @param  Closure(): void  $send
     */
    public static function once(Account $account, string $kind, string $reference, Closure $send): bool
    {
        $notice = self::query()->createOrFirst(['account_id' => $account->id, 'kind' => $kind, 'reference' => $reference], ['sent_at' => now()]);
        if (! $notice->wasRecentlyCreated) {
            return false;
        }
        try {
            $send();
        } catch (Throwable $e) {
            $notice->delete();

            throw $e;
        }

        return true;
    }

    public static function sent(Account $account, string $kind, string $reference = ''): bool
    {
        return self::query()->where('account_id', $account->id)->where('kind', $kind)->where('reference', $reference)->exists();
    }
}
