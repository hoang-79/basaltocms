<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Backend\Schema\Action;
use Basalto\Core\Backend\Schema\AdminPage;
use Basalto\Core\Backend\Schema\Column;
use Basalto\Core\Backend\Schema\Form;
use Basalto\Core\Backend\Schema\FormField;
use Basalto\Core\Backend\Schema\Row;
use Basalto\Core\Backend\Schema\Table;
use Basalto\Core\Choices\ChoiceList;
use Basalto\Core\Choices\ChoiceLists;
use Basalto\Core\Choices\Choices;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Verwaltung › Auswahllisten (CR-022, Entscheid Hoang 15.11.2026): alle Auswahllisten der Site an einem Ort — Werte
 * hinzufügen, umbenennen (nur hinzugefügte), einer Gruppe zuordnen, sortieren, ausblenden. Ausgeblendete Werte bleiben an
 * bestehenden Einträgen stehen, erscheinen aber nicht mehr zur Wahl.
 */
final class ChoiceListsController
{
    public function __construct(private readonly ChoiceLists $lists, private readonly Choices $choices, private readonly CurrentSite $current) {}

    public function index(): AdminPage
    {
        $site = $this->current->require();
        $lists = $this->lists->forSite($site);
        usort($lists, static fn (ChoiceList $a, ChoiceList $b): int => strnatcasecmp($a->label(), $b->label()));

        return AdminPage::make(__('Auswahllisten'))
            ->crumb(__('Verwaltung › Auswahllisten'))
            ->lead(__('Die Werte, die in Auswahlfeldern zur Wahl stehen — etwa Lebenszyklus oder Art einer Aktivität im CRM. Neue Werte fügen Sie direkt im Feld hinzu («+ … hinzufügen»); hier benennen Sie sie um, ordnen sie, blenden sie aus.'))
            ->table(Table::make('listen')
                ->columns([Column::text('name', __('Liste')), Column::text('kind', __('Art')), Column::number('count', __('Werte')), Column::number('added', __('hinzugefügt')), Column::number('hidden', __('ausgeblendet'))])
                ->rows($lists, function (ChoiceList $list) use ($site): Row {
                    $entries = $this->choices->entries($site, $list->key);

                    return Row::make($list->key)->cell('name', $list->label())
                        ->cell('kind', $list->kind === ChoiceList::TEXT ? __('Vorschläge, freie Eingabe') : __('Auswahl'))
                        ->cell('count', count($entries))
                        ->cell('added', count(array_filter($entries, static fn (array $e): bool => $e['origin'] === 'added')))
                        ->cell('hidden', count(array_filter($entries, static fn (array $e): bool => $e['hidden'])))
                        ->url($list->manageUrl() ?? route('basalto.choices.show', ['list' => $list->key]));
                })
                ->empty(__('Keine Auswahllisten'), __('Auswahllisten bringen Module wie CRM oder Stellenangebote mit — sobald eines für die Site an ist, steht seine Liste hier.')));
    }

    public function show(string $list): AdminPage|RedirectResponse
    {
        $site = $this->current->require();
        $definition = $this->definition($site, $list);
        // Werte, die ein Plugin selbst führt, pflegt es auf seiner Seite
        if ($definition->manageUrl() !== null) {
            return redirect()->to($definition->manageUrl());
        }
        $entries = $this->choices->entries($site, $list);
        $grouped = array_filter(array_column($entries, 'group')) !== [];
        $columns = [Column::text('label', __('Wert'))];
        if ($grouped) {
            $columns[] = Column::text('group', __('Gruppe'));
        }
        $columns[] = Column::badge('origin', __('Herkunft'));
        $columns[] = Column::badge('state', __('Stand'));
        $count = count($entries);

        return AdminPage::make($definition->label())
            ->crumb(__('Verwaltung › Auswahllisten'))
            ->lead($definition->kind === ChoiceList::TEXT
                ? __('Vorschläge beim Tippen — ein Eintrag kann auch einen anderen Text tragen. Was schon in Einträgen steht, erscheint hier von selbst.')
                : __('Diese Werte stehen zur Wahl. Vorgegebene Werte lassen sich ausblenden, aber nicht umbenennen; hinzugefügte auch umbenennen.'))
            ->action(Action::link(__('‹ Auswahllisten'), route('basalto.choices.index')))
            ->table(Table::make('werte')->columns($columns)
                ->rows(array_map(null, array_keys($entries), $entries), function (array $pair) use ($list, $count, $entries): Row {
                    [$index, $entry] = $pair;
                    $row = Row::make($entry['value'])->cell('label', $entry['label'])->cell('group', $entry['group'] ?? '—')
                        ->badge('origin', match ($entry['origin']) {
                            'builtin' => __('vorgegeben'), 'added' => __('hinzugefügt'), default => __('in Einträgen'),
                        }, $entry['origin'] === 'added' ? 'success' : 'neutral')
                        ->badge('state', $entry['hidden'] ? __('ausgeblendet') : __('zur Wahl'), $entry['hidden'] ? 'warning' : 'success')
                        ->url(route('basalto.choices.edit', ['list' => $list, 'wert' => $entry['value']]));
                    if ($index > 0 && $entries[$index - 1]['group'] === $entry['group']) {
                        $row->action(Action::post(__('Nach oben'), route('basalto.choices.move', ['list' => $list]))->with(['value' => $entry['value'], 'direction' => -1]));
                    }
                    if ($index < $count - 1 && $entries[$index + 1]['group'] === $entry['group']) {
                        $row->action(Action::post(__('Nach unten'), route('basalto.choices.move', ['list' => $list]))->with(['value' => $entry['value'], 'direction' => 1]));
                    }

                    return $row->action(Action::post($entry['hidden'] ? __('Einblenden') : __('Ausblenden'), route('basalto.choices.hide', ['list' => $list]))
                        ->with(['value' => $entry['value'], 'hidden' => ! $entry['hidden']]));
                }))
            ->form(Form::make(route('basalto.choices.store', ['list' => $list]))->title(__('Wert hinzufügen'))->submit(__('Hinzufügen'))->fields([
                FormField::text('label', __('Bezeichnung'))->required()->half(),
                ...($grouped ? [FormField::select('group', __('Gruppe'), ['' => __('keine')] + $this->groupChoices($entries))->half()] : []),
            ])->values(['label' => '', 'group' => '']));
    }

    public function store(Request $request, string $list): RedirectResponse
    {
        $site = $this->current->require();
        $this->definition($site, $list, true);
        $data = $request->validate(['label' => ['required', 'string', 'max:160'], 'group' => ['nullable', 'string', 'max:120']], [], ['label' => __('Bezeichnung'), 'group' => __('Gruppe')]);
        $option = $this->choices->add($site, $list, (string) $data['label'], is_string($data['group'] ?? null) ? $data['group'] : null, (int) $request->user()?->getAuthIdentifier());

        return back()->with('status', __('«:label» steht zur Wahl.', ['label' => $option['label']]));
    }

    public function edit(Request $request, string $list): AdminPage
    {
        $site = $this->current->require();
        $definition = $this->definition($site, $list, true);
        $entry = $this->find($site, $list, (string) $request->query('wert', ''));
        $entries = $this->choices->entries($site, $list);
        $renamable = $entry['origin'] === 'added' && $definition->kind === ChoiceList::SELECT;
        $groups = $this->groupChoices($entries);

        return AdminPage::make($entry['label'])
            ->crumb(__('Verwaltung › Auswahllisten › :list', ['list' => $definition->label()]))
            ->action(Action::link(__('‹ :list', ['list' => $definition->label()]), route('basalto.choices.show', ['list' => $list])))
            ->form(Form::make(route('basalto.choices.update', ['list' => $list, 'wert' => $entry['value']]), 'put')->title(__('Wert'))->autosave()->fields([
                ...($renamable ? [FormField::text('label', __('Bezeichnung'))->required()->half()] : []),
                FormField::text('group', __('Gruppe'))->half()->help($groups !== [] ? __('Bestehende Gruppen: :groups', ['groups' => implode(', ', array_keys($groups))]) : __('Leer: ohne Gruppe.')),
            ])->values(['label' => $entry['label'], 'group' => $entry['group'] ?? '']));
    }

    public function update(Request $request, string $list): RedirectResponse
    {
        $site = $this->current->require();
        $this->definition($site, $list, true);
        $entry = $this->find($site, $list, (string) $request->query('wert', ''));
        $data = $request->validate(['label' => ['nullable', 'string', 'max:160'], 'group' => ['nullable', 'string', 'max:120']]);
        $this->choices->update($site, $list, $entry['value'], is_string($data['label'] ?? null) ? $data['label'] : null, is_string($data['group'] ?? null) ? $data['group'] : null);

        return back();
    }

    public function hide(Request $request, string $list): RedirectResponse
    {
        $site = $this->current->require();
        $this->definition($site, $list, true);
        $data = $request->validate(['value' => ['required', 'string'], 'hidden' => ['required', 'boolean']]);
        $entry = $this->find($site, $list, (string) $data['value']);
        $this->choices->setHidden($site, $list, $entry['value'], (bool) $data['hidden']);

        return back()->with('status', (bool) $data['hidden'] ? __('«:label» ist ausgeblendet.', ['label' => $entry['label']]) : __('«:label» steht wieder zur Wahl.', ['label' => $entry['label']]));
    }

    public function move(Request $request, string $list): RedirectResponse
    {
        $site = $this->current->require();
        $this->definition($site, $list, true);
        $data = $request->validate(['value' => ['required', 'string'], 'direction' => ['required', 'integer', 'in:-1,1']]);
        $this->choices->move($site, $list, $this->find($site, $list, (string) $data['value'])['value'], (int) $data['direction']);

        return back();
    }

    private function definition(Site $site, string $list, bool $managed = false): ChoiceList
    {
        $definition = $this->lists->get($list);
        abort_if($definition === null || ! in_array($definition, $this->lists->forSite($site), true), 404);
        abort_if($managed && $definition->isExternal(), 404);

        return $definition;
    }

    /** @return array{value: string, label: string, group: string|null, origin: string, hidden: bool} */
    private function find(Site $site, string $list, string $value): array
    {
        foreach ($this->choices->entries($site, $list) as $entry) {
            if ($entry['value'] === $value) {
                return $entry;
            }
        }
        abort(404);
    }

    /**
     * @param  list<array{group: string|null}>  $entries
     * @return array<string, string>
     */
    private function groupChoices(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            if ($entry['group'] !== null) {
                $out[$entry['group']] = $entry['group'];
            }
        }

        return $out;
    }
}
