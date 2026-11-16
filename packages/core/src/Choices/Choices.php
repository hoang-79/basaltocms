<?php

declare(strict_types=1);

namespace Basalto\Core\Choices;

use Basalto\Core\Models\ChoiceOption;
use Basalto\Core\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Die Werte der Auswahllisten je Site (CR-022): vorgegebene aus dem Code, hinzugefügte aus `choice_options`, bei Listen
 * mit Text dazu, was schon in Einträgen steht. Eine Zeile zu einem vorgegebenen oder verwendeten Wert hält nur
 * Gruppe, Reihenfolge und Ausblenden fest. Ausgeblendete Werte gelten weiter (bestehende Einträge behalten sie), stehen
 * aber nicht mehr zur Wahl.
 *
 * @phpstan-type Entry array{value: string, label: string, group: string|null, origin: 'builtin'|'added'|'used', hidden: bool, fill?: array<string, string>}
 * @phpstan-type Option array{value: string, label: string, group: string|null, fill?: array<string, string>}
 */
final class Choices
{
    /** @var array<string, Collection<string, ChoiceOption>> */
    private array $rows = [];

    public function __construct(private readonly ChoiceLists $lists) {}

    public function list(string $key): ChoiceList
    {
        return $this->lists->get($key) ?? throw new InvalidArgumentException('Unbekannte Auswahlliste: '.$key);
    }

    /**
     * Alle Werte in Anzeigereihenfolge, auch ausgeblendete — für die Verwaltung.
     *
     * @return list<Entry>
     */
    public function entries(Site $site, string $key): array
    {
        $list = $this->list($key);
        if ($list->isExternal()) {
            return array_map(static fn (array $value): array => ['value' => $value['value'], 'label' => $value['label'], 'group' => $value['group'] ?? null, 'origin' => 'added', 'hidden' => false],
                $list->externalValues($site));
        }
        $rows = $this->rows($site, $key);
        /** @var array<string, array{entry: Entry, order: int}> $out */
        $out = [];
        $seq = 0;
        foreach ($list->builtin() as $value) {
            $row = $rows->get($value['value']);
            $out[$value['value']] = ['entry' => ['value' => $value['value'], 'label' => $value['label'], 'group' => $row->group ?? ($value['group'] ?? null), 'origin' => 'builtin',
                'hidden' => $row?->hidden_at !== null] + (isset($value['fill']) ? ['fill' => $value['fill']] : []), 'order' => $row->position ?? $seq];
            $seq++;
        }
        foreach ($rows->sortBy('id') as $row) {
            if (isset($out[$row->value]) || $row->label === null) {
                continue;
            }
            $out[$row->value] = ['entry' => ['value' => $row->value, 'label' => $row->label, 'group' => $row->group, 'origin' => 'added', 'hidden' => $row->hidden_at !== null],
                'order' => $row->position ?? 100000 + $row->id];
        }
        if ($list->kind === ChoiceList::TEXT) {
            $used = $list->usedValues($site);
            usort($used, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
            foreach ($used as $value) {
                if (isset($out[$value['value']]) || $this->labelTaken($out, $value['label'])) {
                    continue;
                }
                $row = $rows->get($value['value']);
                $out[$value['value']] = ['entry' => ['value' => $value['value'], 'label' => $value['label'], 'group' => $row->group ?? ($value['group'] ?? null), 'origin' => 'used',
                    'hidden' => $row?->hidden_at !== null] + (isset($value['fill']) ? ['fill' => $value['fill']] : []), 'order' => $row->position ?? 200000 + $seq++];
            }
        }
        $groups = $list->groupOrder();
        $rank = static fn (?string $group): int => $group === null ? PHP_INT_MAX : (int) (array_search($group, $groups, true) === false ? count($groups) : array_search($group, $groups, true));
        uasort($out, static fn (array $a, array $b): int => [$rank($a['entry']['group']), $a['order']] <=> [$rank($b['entry']['group']), $b['order']]);

        return array_values(array_map(static fn (array $item): array => $item['entry'], $out));
    }

    /**
     * Was im Feld zur Wahl steht: sichtbare Werte, dazu der aktuelle Wert, auch wenn er ausgeblendet oder unbekannt ist.
     *
     * @return list<Option>
     */
    public function options(Site $site, string $key, ?string $current = null): array
    {
        $out = [];
        $found = false;
        foreach ($this->entries($site, $key) as $entry) {
            $isCurrent = $current !== null && $entry['value'] === $current;
            $found = $found || $isCurrent;
            if ($entry['hidden'] && ! $isCurrent) {
                continue;
            }
            $out[] = ['value' => $entry['value'], 'label' => $entry['label'], 'group' => $entry['group']] + (isset($entry['fill']) ? ['fill' => $entry['fill']] : []);
        }
        if ($current !== null && $current !== '' && ! $found) {
            $out[] = ['value' => $current, 'label' => $current, 'group' => null];
        }

        return $out;
    }

    /**
     * Gültige Werte einer Liste mit Schlüsseln (`select`), auch ausgeblendete — für die Prüfung im Controller.
     *
     * @return list<string>
     */
    public function values(Site $site, string $key): array
    {
        return array_map(static fn (array $entry): string => $entry['value'], $this->entries($site, $key));
    }

    /** Die Bezeichnung eines Werts — unbekannte Werte so, wie sie gespeichert sind. */
    public function label(Site $site, string $key, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        foreach ($this->entries($site, $key) as $entry) {
            if ($entry['value'] === $value) {
                return $entry['label'];
            }
        }

        return $value;
    }

    /** @return array<string, string> Wert → Bezeichnung */
    public function labels(Site $site, string $key): array
    {
        $out = [];
        foreach ($this->entries($site, $key) as $entry) {
            $out[$entry['value']] = $entry['label'];
        }

        return $out;
    }

    /**
     * Einen Wert hinzufügen. Gibt es die Bezeichnung schon (auch ausgeblendet, gross/klein egal), gilt der bestehende
     * Wert und erscheint wieder.
     *
     * @return Option
     */
    public function add(Site $site, string $key, string $label, ?string $group = null, ?int $userId = null): array
    {
        $list = $this->list($key);
        $label = trim((string) preg_replace('/\s+/u', ' ', $label));
        if ($label === '') {
            throw new InvalidArgumentException('Leere Bezeichnung');
        }
        foreach ($this->entries($site, $key) as $entry) {
            if (mb_strtolower($entry['label']) === mb_strtolower($label) || $entry['value'] === $label) {
                if ($entry['hidden']) {
                    $this->setHidden($site, $key, $entry['value'], false);
                }

                return ['value' => $entry['value'], 'label' => $entry['label'], 'group' => $entry['group']];
            }
        }
        if ($list->isExternal()) {
            $created = $list->createExternal($site, mb_substr($label, 0, 160), $userId);

            return ['value' => $created['value'], 'label' => $created['label'], 'group' => $created['group'] ?? null];
        }
        $value = $list->kind === ChoiceList::TEXT ? mb_substr($label, 0, 120) : $this->newKey($site, $key, $label);
        $group = $group !== null && trim($group) !== '' ? mb_substr(trim($group), 0, 120) : null;
        ChoiceOption::query()->create(['site_id' => $site->id, 'list' => $key, 'value' => $value, 'label' => mb_substr($label, 0, 160), 'group' => $group, 'created_by' => $userId]);
        $this->forget($site, $key);

        return ['value' => $value, 'label' => mb_substr($label, 0, 160), 'group' => $group];
    }

    /** Bezeichnung (nur hinzugefügte Werte mit Schlüssel) und Gruppe ändern. */
    public function update(Site $site, string $key, string $value, ?string $label, ?string $group): void
    {
        $entry = $this->entry($site, $key, $value);
        $row = $this->row($site, $key, $value);
        $changes = ['group' => $group !== null && trim($group) !== '' ? mb_substr(trim($group), 0, 120) : null];
        if ($entry['origin'] === 'added' && $this->list($key)->kind === ChoiceList::SELECT && $label !== null && trim($label) !== '') {
            $changes['label'] = mb_substr(trim($label), 0, 160);
        }
        $row->forceFill($changes)->save();
        $this->forget($site, $key);
    }

    public function setHidden(Site $site, string $key, string $value, bool $hidden): void
    {
        $this->entry($site, $key, $value);
        $this->row($site, $key, $value)->forceFill(['hidden_at' => $hidden ? now() : null])->save();
        $this->forget($site, $key);
    }

    /** Einen Platz nach oben (-1) oder unten (+1) — innerhalb der Gruppe des Werts. */
    public function move(Site $site, string $key, string $value, int $direction): void
    {
        $entries = $this->entries($site, $key);
        $index = array_search($value, array_column($entries, 'value'), true);
        if (! is_int($index)) {
            return;
        }
        $target = $index + ($direction < 0 ? -1 : 1);
        if (! isset($entries[$target]) || $entries[$target]['group'] !== $entries[$index]['group']) {
            return;
        }
        [$entries[$index], $entries[$target]] = [$entries[$target], $entries[$index]];
        // Die Reihenfolge der ganzen Liste festhalten — sonst wäre ein einzelner Platz nicht vergleichbar
        foreach ($entries as $position => $entry) {
            $this->row($site, $key, $entry['value'])->forceFill(['position' => $position])->save();
        }
        $this->forget($site, $key);
    }

    /** @return Entry */
    private function entry(Site $site, string $key, string $value): array
    {
        foreach ($this->entries($site, $key) as $entry) {
            if ($entry['value'] === $value) {
                return $entry;
            }
        }

        throw new InvalidArgumentException('Unbekannter Wert');
    }

    private function row(Site $site, string $key, string $value): ChoiceOption
    {
        return $this->rows($site, $key)->get($value)
            ?? ChoiceOption::query()->firstOrNew(['site_id' => $site->id, 'list' => $key, 'value' => $value]);
    }

    /** Ein Schlüssel aus der Bezeichnung («Wartet auf Offerte» → «wartet-auf-offerte»), eindeutig in der Liste. */
    private function newKey(Site $site, string $key, string $label): string
    {
        $base = Str::slug($label, '-', 'de');
        $base = $base !== '' ? mb_substr($base, 0, 60) : 'wert';
        $taken = array_flip($this->values($site, $key));
        $candidate = $base;
        for ($i = 2; isset($taken[$candidate]); $i++) {
            $candidate = $base.'-'.$i;
        }

        return $candidate;
    }

    /** @param  array<string, array{entry: Entry, order: int}>  $out */
    private function labelTaken(array $out, string $label): bool
    {
        foreach ($out as $item) {
            if (mb_strtolower($item['entry']['label']) === mb_strtolower($label)) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<string, ChoiceOption> */
    private function rows(Site $site, string $key): Collection
    {
        return $this->rows[$site->id.'|'.$key] ??= ChoiceOption::query()->where('site_id', $site->id)->where('list', $key)->get()->keyBy('value');
    }

    private function forget(Site $site, string $key): void
    {
        unset($this->rows[$site->id.'|'.$key]);
    }
}
