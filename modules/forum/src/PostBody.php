<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum;

use Basalto\Core\Community\MessageBody;
use Illuminate\Http\Request;

/**
 * Der Text eines Beitrags aus dem Formular (CR-006 FO-05/FO-K5): mit Editor-Insel das Dokument aus `<feld>_doc`,
 * gesäubert mit der Positivliste der Community (`CommunityRichText`); ohne Skript der Text aus dem Textfeld — Leerzeilen trennen Absätze,
 * einfache Umbrüche bleiben Umbrüche. Seit TB 40 liegt das Lesen im Kern ({@see MessageBody}), auch für Konversationen.
 */
final class PostBody
{
    public const MAX_TEXT = MessageBody::MAX_TEXT;

    /** @return array{type: 'doc', content: list<array<string, mixed>>}|null */
    public static function fromRequest(Request $request, string $field = 'body'): ?array
    {
        return MessageBody::fromRequest($request, $field);
    }

    /** @return array{type: 'doc', content: list<array<string, mixed>>}|null */
    public static function fromText(string $text): ?array
    {
        return MessageBody::fromText($text);
    }

    /** @param  array<string, mixed>|null  $doc */
    public static function length(?array $doc): int
    {
        return MessageBody::length($doc);
    }
}
