<?php

declare(strict_types=1);

namespace Basalto\Lava\Theme;

use Basalto\Lava\Diagnostics\Diagnostic;
use Basalto\Lava\Diagnostics\Step;
use Basalto\Lava\Structure\Definition;

/**
 * Die rote Linie als Statusbericht: je Schritt A–Z «✓», «✗» oder «–» mit einer kurzen Zusammenfassung —
 * dieselbe Ausgabe für «basalto:check» und die Backend-Einrichtung.
 */
final class Line
{
    /** @param  list<Diagnostic>  $diagnostics */
    public function __construct(private readonly ?Theme $theme, private readonly array $diagnostics) {}

    /** @return list<array{letter: string, keyword: string, status: string, summary: string}> */
    public function rows(): array
    {
        $rows = [];
        $doc = $this->theme?->document;
        $blockedByEarlier = false;
        foreach (Step::LETTERS as $keyword => $letter) {
            $errors = array_values(array_filter($this->diagnostics, static fn (Diagnostic $d): bool => $d->isError() && $d->step === $letter));
            $defs = $doc?->all($keyword) ?? [];
            if ($keyword === 'type') {
                $defs = array_merge($defs, $doc?->all('global') ?? []);
            }
            if ($errors !== []) {
                $status = '✗';
                $summary = $errors[0]->message.($errors[0]->suggestion ? sprintf(' — meinten Sie «%s»?', $errors[0]->suggestion) : '').sprintf(' (Zeile %d)', $errors[0]->line);
            } elseif ($defs === []) {
                $status = $keyword === 'publish' && $blockedByEarlier ? '–' : '✗';
                $summary = $keyword === 'publish' && $blockedByEarlier ? 'wartet auf die Schritte davor' : sprintf('«%s» fehlt', $keyword);
            } else {
                $status = '✓';
                $summary = $this->summary($keyword, $defs);
            }
            if ($status === '✗') {
                $blockedByEarlier = true;
            }
            $rows[] = ['letter' => $letter, 'keyword' => $keyword, 'status' => $status, 'summary' => $summary];
        }

        return $rows;
    }

    public function render(): string
    {
        $out = '';
        foreach ($this->rows() as $r) {
            $out .= sprintf("%s %-10s %s %s\n", $r['letter'], $r['keyword'], $r['status'], $r['summary']);
        }

        return $out;
    }

    public function isComplete(): bool
    {
        foreach ($this->rows() as $r) {
            if ($r['status'] !== '✓') {
                return false;
            }
        }

        return true;
    }

    /** @param  list<Definition>  $defs */
    private function summary(string $keyword, array $defs): string
    {
        $names = array_values(array_filter(array_map(static fn ($d): ?string => $d->name, $defs)));
        $first = $defs[0];

        return match ($keyword) {
            'site' => sprintf('%s · %d Sprachen · %s', $first->name, count($first->property('languages')->values ?? []), $first->value('domain') ?? 'ohne Domain'),
            'design' => implode(' · ', array_filter([
                sprintf('%d Farben', count($first->childrenOf('color'))),
                match (count($first->childrenOf('palette'))) {
                    0 => '',
                    1 => '1 Farbvariante',
                    default => sprintf('%d Farbvarianten', count($first->childrenOf('palette'))),
                },
                sprintf('%d Schriften', count($first->childrenOf('font'))),
            ])),
            'layout' => implode(' · ', array_map(static fn ($d): string => sprintf('%s (%d Regionen)', $d->name, count($d->childrenOf('region'))), $defs)),
            'block' => sprintf('%d Blöcke', count($defs)),
            'form' => implode(' · ', array_map(static fn ($d): string => sprintf('%s → %s', $d->name, $d->value('to') ?? 'ohne Empfänger'), $defs)),
            'seed' => sprintf('%d Seiten · %d globale Inhalte', count($first->childrenOf('page')), count($first->childrenOf('global'))),
            'publish' => sprintf('Sitemap %s · Prüfungen: %s', $first->value('sitemap') ?? 'aus', $first->property('check')?->text() ?? 'keine'),
            default => implode(' · ', $names),
        };
    }
}
