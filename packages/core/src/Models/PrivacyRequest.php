<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Basalto\Core\Sites\BelongsToSite;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Ein Anliegen zum Datenschutz aus dem Kundenkonto (CR-011 TB 61): Art, Text, Eingang und Frist (ein Monat), erledigt
 * mit Antwort an die Person.
 *
 * @property int $id
 * @property int $site_id
 * @property ?int $customer_id
 * @property string $email
 * @property ?string $name
 * @property string $kind
 * @property string $message
 * @property string $locale
 * @property string $status
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable $due_at
 * @property ?CarbonImmutable $handled_at
 * @property ?int $handled_by
 * @property ?string $answer
 */
final class PrivacyRequest extends Model
{
    use BelongsToSite;

    public const OPEN = 'open';

    public const DONE = 'done';

    /** Arten mit ihrer Beschriftung (deutsch, übersetzt mit `__()`) und dem Artikel der DSGVO. */
    public const KINDS = [
        'berichtigung' => ['Berichtigung (etwa der E-Mail-Adresse)', 'Art. 16 DSGVO'],
        'einschraenkung' => ['Einschränkung der Bearbeitung', 'Art. 18 DSGVO'],
        'widerspruch' => ['Widerspruch gegen eine Bearbeitung', 'Art. 21 DSGVO'],
        'sonstiges' => ['Anderes Anliegen zum Datenschutz', 'Art. 12 DSGVO'],
    ];

    protected $fillable = ['site_id', 'customer_id', 'email', 'name', 'kind', 'message', 'locale', 'status', 'received_at', 'due_at', 'handled_at', 'handled_by', 'answer'];

    protected function casts(): array
    {
        return ['received_at' => 'immutable_datetime', 'due_at' => 'immutable_datetime', 'handled_at' => 'immutable_datetime'];
    }

    public static function kindLabel(string $kind, ?string $locale = null): string
    {
        return (string) __(self::KINDS[$kind][0] ?? self::KINDS['sonstiges'][0], [], $locale);
    }
}
