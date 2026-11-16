<?php

declare(strict_types=1);

namespace Basalto\Core\Backend\Schema;

use Basalto\Core\Access\Access;
use Basalto\Core\Choices\ChoiceLists;
use Basalto\Core\Choices\Choices;
use Basalto\Core\Http\Controllers\ChoiceController;
use Basalto\Core\Lookup\Lookups;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Support\Countries;

/**
 * Ein Formular auf einer Verwaltungsseite (CR-018, TB 99). Mit `autosave()` speichert es nach jeder Pause selbst —
 * für Bestehendes (CR-013); ohne mit einem Knopf, etwa beim Anlegen. Geprüft wird im Controller des Plugins wie immer
 * (`$request->validate`), die Fehler stehen am Feld.
 */
final class Form
{
    private ?string $title = null;

    /** @var list<FormField> */
    private array $fields = [];

    /** @var array<string, mixed> */
    private array $values = [];

    private bool $autosave = false;

    private string $submit = '';

    /** @var list<string> CR-021: Felder, deren erster Eintrag den Datensatz anlegt (Anlegen ohne Knopf) */
    private array $creates = [];

    private function __construct(private readonly string $url, private readonly string $method) {}

    /** `$method`: `post` oder `put`. */
    public static function make(string $url, string $method = 'post'): self
    {
        return new self($url, $method === 'put' ? 'put' : 'post');
    }

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /** @param  list<FormField>  $fields */
    public function fields(array $fields): self
    {
        $this->fields = $fields;

        return $this;
    }

    /** @param  array<string, mixed>  $values  Feldname → Wert */
    public function values(array $values): self
    {
        $this->values = $values;

        return $this;
    }

    public function autosave(bool $autosave = true): self
    {
        $this->autosave = $autosave;

        return $this;
    }

    /**
     * CR-021: Anlegen ohne Knopf — der Eintrag entsteht, sobald eines dieser Felder ausgefüllt und verlassen ist (die
     * Adresse des Formulars nimmt alle Werte wie beim Knopf); die Antwort führt auf die Seite des neuen Eintrags, dort
     * speichert das Formular selbst. Getipptes, das unterwegs dazukam, geht mit.
     *
     * @param  list<string>  $fields
     */
    public function creates(array $fields): self
    {
        $this->creates = $fields;
        $this->autosave = false;

        return $this;
    }

    public function submit(string $label): self
    {
        $this->submit = $label;

        return $this;
    }

    /**
     * CR-022: Felder mit Auswahlliste bekommen die Werte der Site und — wer hinzufügen darf — die Adresse dafür.
     *
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private static function withChoices(array $field, mixed $current): array
    {
        $key = $field['choice_list'] ?? null;
        $site = is_string($key) ? app(CurrentSite::class)->get() : null;
        $list = is_string($key) ? app(ChoiceLists::class)->get($key) : null;
        if ($site === null || $list === null) {
            return $field;
        }
        $user = auth()->user();
        $field['options'] = app(Choices::class)->options($site, $key, is_scalar($current) ? (string) $current : null);
        $field['choice_add'] = $user !== null && ChoiceController::mayAdd(app(Access::class), $user, $site, $list->areas()) ? route('basalto.choice_add', ['list' => $key]) : null;
        $field['choice_kind'] = $list->kind;

        return $field;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $values = [];
        foreach ($this->fields as $field) {
            $values[$field->name] = $this->values[$field->name] ?? null;
        }

        $fields = array_map(static fn (FormField $f): array => self::withChoices($f->toArray(), $values[$f->name] ?? null), $this->fields);
        $types = array_column($fields, 'type');
        $kinds = array_values(array_unique(array_filter(array_map(static fn (array $f): ?string => $f['lookup']['kind'] ?? null, $fields))));

        return [
            'kind' => 'form', 'title' => $this->title, 'url' => $this->url, 'method' => $this->method, 'autosave' => $this->autosave,
            'submit' => $this->submit !== '' ? $this->submit : (string) __('Speichern'),
            'fields' => $fields, 'values' => $values, 'creates' => $this->creates,
            // CR-021: Länder nur, wenn ein Feld sie braucht — einmal je Formular
            'countries' => array_intersect(['country', 'phone', 'postal'], $types) !== [] ? Countries::forForms() : null,
            'lookups' => $kinds === [] ? null : Lookups::urls($kinds),
        ];
    }
}
