<?php

declare(strict_types=1);

namespace Basalto\Modules\Comments;

/**
 * Die Einstellungen einer Einbindung des Blocks «Kommentare» (M-27): Moderation, Sortierung, Anzahl je Seite,
 * Antworten, Benachrichtigung und «geschlossen». Das Theme reicht sie als benannte Werte an den Tag
 * `{% comments.list %}` — deutsch wie die Auswahl im Block (`Freigabe`, `sofort`, `neueste-zuerst`, `keine`, `aus`,
 * `Autor`) oder englisch. Was der Versand braucht, steht signiert im Formular: die Seite kommt aus dem Seiten-Cache
 * und kennt keine Sitzung, und eine Einstellung soll niemand von aussen ändern können.
 */
final readonly class Embedding
{
    public const NOTIFY_OFF = 'off';

    public const NOTIFY_EDITORS = 'editors';

    public const NOTIFY_AUTHOR = 'author';

    public function __construct(
        public bool $moderated = true,
        public bool $newestFirst = false,
        public int $perPage = 20,
        public bool $replies = true,
        public string $notify = self::NOTIFY_EDITORS,
        public bool $closed = false,
    ) {}

    /** @param  array<string, mixed>  $named  benannte Werte des Tags */
    public static function fromNamed(array $named): self
    {
        $word = static fn (string $key): string => mb_strtolower(trim(is_scalar($named[$key] ?? null) ? (string) $named[$key] : ''));
        $per = $named['per'] ?? null;

        return new self(
            moderated: ! in_array($word('moderation'), ['sofort', 'nachträglich', 'after', 'immediately', 'off'], true),
            newestFirst: str_starts_with($word('order'), 'neu') || str_starts_with($word('order'), 'new'),
            perPage: is_numeric($per) ? max(1, min(100, (int) $per)) : 20,
            replies: ! in_array($word('replies'), ['keine', 'none', 'off', 'false', '0'], true),
            notify: match ($word('notify')) {
                'aus', 'off', 'none', 'false', '0' => self::NOTIFY_OFF,
                'autor', 'author' => self::NOTIFY_AUTHOR,
                default => self::NOTIFY_EDITORS,
            },
            closed: self::truthy($named['closed'] ?? null),
        );
    }

    /** Was der Versand braucht, signiert und an den Inhalt gebunden. */
    public function sign(int $entryId): string
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode(['m' => $this->moderated, 'r' => $this->replies, 'n' => $this->notify])), '+/', '-_'), '=');

        return $payload.'.'.self::signature($entryId, $payload);
    }

    /** Die Einstellungen aus dem Formular — null, wenn die Signatur nicht passt. */
    public static function fromSigned(int $entryId, string $value): ?self
    {
        [$payload, $signature] = array_pad(explode('.', $value, 2), 2, '');
        if ($payload === '' || ! hash_equals(self::signature($entryId, $payload), $signature)) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
        if (! is_array($data)) {
            return null;
        }
        $notify = in_array($data['n'] ?? null, [self::NOTIFY_OFF, self::NOTIFY_EDITORS, self::NOTIFY_AUTHOR], true) ? (string) $data['n'] : self::NOTIFY_OFF;

        return new self(moderated: ($data['m'] ?? true) !== false, replies: ($data['r'] ?? false) === true, notify: $notify);
    }

    private static function signature(int $entryId, string $payload): string
    {
        return hash_hmac('sha256', 'comments|'.$entryId.'|'.$payload, (string) config('app.key'));
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || in_array(is_string($value) ? mb_strtolower(trim($value)) : null, ['1', 'true', 'ja', 'yes', 'on'], true);
    }
}
