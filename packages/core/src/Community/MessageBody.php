<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\Content\RichText;
use Illuminate\Http\Request;

/**
 * Text aus einem Formular der Community (CR-006 FO-K5): mit Editor-Insel das Dokument aus `<feld>_doc`, gesäubert
 * mit der Positivliste ({@see CommunityRichText}); ohne Skript der Text aus dem Textfeld — Leerzeilen trennen Absätze,
 * einfache Umbrüche bleiben Umbrüche. Beiträge im Forum und Nachrichten in Konversationen lesen ihren Text so.
 */
final class MessageBody
{
    public const MAX_TEXT = 20000;

    /** @return array{type: 'doc', content: list<array<string, mixed>>}|null */
    public static function fromRequest(Request $request, string $field = 'body'): ?array
    {
        $json = $request->input($field.'_doc');
        if (is_string($json) && $json !== '' && strlen($json) < 200000) {
            $doc = CommunityRichText::clean(json_decode($json, true));
            if ($doc !== null) {
                return $doc;
            }
        }
        $text = $request->input($field);

        return is_string($text) ? self::fromText($text) : null;
    }

    /** @return array{type: 'doc', content: list<array<string, mixed>>}|null */
    public static function fromText(string $text): ?array
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ($text === '') {
            return null;
        }
        $content = [];
        foreach (preg_split('/\n{2,}/', $text) ?: [] as $block) {
            $inline = [];
            foreach (explode("\n", trim($block)) as $i => $line) {
                if ($i > 0) {
                    $inline[] = ['type' => 'hardBreak'];
                }
                if ($line !== '') {
                    $inline[] = ['type' => 'text', 'text' => $line];
                }
            }
            $content[] = ['type' => 'paragraph', 'content' => $inline];
        }

        return ['type' => 'doc', 'content' => $content];
    }

    /** @param  array<string, mixed>|null  $doc */
    public static function length(?array $doc): int
    {
        return mb_strlen(RichText::toText($doc));
    }
}
