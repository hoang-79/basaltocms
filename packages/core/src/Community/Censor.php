<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\Models\Site;

/**
 * Zensur (CR-006 FO-17): eine Wortliste je Site; ein Treffer blockiert das Absenden mit einem Hinweis, welches Wort
 * stört — nichts wird still verändert. Ganze Wörter, gross/klein egal; `*` steht für beliebig viele Zeichen
 * (`spam*` trifft «Spammer»).
 */
final class Censor
{
    public function __construct(private readonly ModerationSettings $settings) {}

    /** @return list<string> die gefundenen Wörter der Liste */
    public function matches(Site $site, string ...$texts): array
    {
        $words = $this->settings->all($site)['censor'];
        if ($words === []) {
            return [];
        }
        $text = implode("\n", $texts);
        $found = [];
        foreach ($words as $word) {
            $word = trim($word);
            if ($word === '') {
                continue;
            }
            $pattern = '/(?<![\p{L}\p{N}])'.str_replace('\*', '[\p{L}\p{N}]*', preg_quote($word, '/')).'(?![\p{L}\p{N}])/iu';
            if (preg_match($pattern, $text) === 1) {
                $found[] = $word;
            }
        }

        return $found;
    }

    /** Der Fehlertext fürs Formular oder null. */
    public function error(Site $site, string $lang, string ...$texts): ?string
    {
        $found = $this->matches($site, ...$texts);

        return $found === [] ? null : (string) __('Bitte formulieren Sie ohne: :words', ['words' => implode(', ', array_map(static fn (string $w): string => '«'.$w.'»', $found))], $lang);
    }
}
