<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ein Eingang eines Formulars (M-11, N-05): Werte je Feldname, Dateien auf dem privaten Speicher, IP nur als
 * Prüfsumme (HMAC mit dem App-Schlüssel), Zeitpunkt der Zustimmung; Status für die Redaktion (neu, gelesen,
 * beantwortet) und für Mail und Webhook (pending, sent, failed, none).
 *
 * @property int $id
 * @property int $form_id
 * @property int $site_id
 * @property string $locale
 * @property string|null $page_path
 * @property array<string, mixed> $data
 * @property array<string, array{path: string, name: string, size: int, mime: string}>|null $files
 * @property string|null $ip_hash
 * @property Carbon|null $consent_at
 * @property string $status
 * @property string|null $mail_status
 * @property string|null $mail_error
 * @property string|null $webhook_status
 * @property string|null $webhook_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class FormSubmission extends Model
{
    use BelongsToSite;

    public const STATUSES = ['new', 'read', 'answered'];

    protected $fillable = ['form_id', 'site_id', 'locale', 'page_path', 'data', 'files', 'ip_hash', 'consent_at', 'status', 'mail_status', 'mail_error', 'webhook_status', 'webhook_error'];

    protected function casts(): array
    {
        return ['data' => 'array', 'files' => 'array', 'consent_at' => 'datetime'];
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }
}
