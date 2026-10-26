<?php

declare(strict_types=1);

namespace Basalto\Lava\Expression;

use Basalto\Lava\Diagnostics\LavaSyntaxException;

/**
 * Tokens eines Ausdrucks innerhalb von {{ }} oder {% %}.
 *
 * @return list<array{0: string, 1: string}>
 */
final class Tokenizer
{
    private const PATTERN = '/\s*(?:(\d+(?:\.\d+)?)|("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')|([A-Za-z_][A-Za-z0-9_]*)|(==|!=|<=|>=|&&|\|\||[-+*\/%~<>()\[\]{},.:|=!?]))/A';

    /** @return list<array{0: string, 1: string}> */
    public static function tokenize(string $source, string $file, int $line): array
    {
        $tokens = [];
        $offset = 0;
        $length = strlen($source);
        while ($offset < $length) {
            if (trim(substr($source, $offset)) === '') {
                break;
            }
            if (! preg_match(self::PATTERN, $source, $m, PREG_UNMATCHED_AS_NULL, $offset)) {
                throw new LavaSyntaxException(sprintf('Unverständlicher Ausdruck bei «%s»', substr($source, $offset, 12)), $file, $line, $offset + 1);
            }
            $offset += strlen($m[0]);
            if ($m[1] !== null) {
                $tokens[] = ['number', $m[1]];
            } elseif ($m[2] !== null) {
                $tokens[] = ['string', stripcslashes(substr($m[2], 1, -1))];
            } elseif ($m[3] !== null) {
                $tokens[] = [in_array($m[3], ['and', 'or', 'not', 'in', 'if', 'else', 'true', 'false', 'null', 'as', 'with'], true) ? 'keyword' : 'name', $m[3]];
            } else {
                $tokens[] = ['op', $m[4] ?? ''];
            }
        }
        $tokens[] = ['eof', ''];

        return $tokens;
    }
}
