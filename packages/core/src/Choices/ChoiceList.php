<?php

declare(strict_types=1);

namespace Basalto\Core\Choices;

use Basalto\Core\Models\Site;
use Closure;

/**
 * Eine Auswahlliste (CR-022): die Werte eines Felds, die eine Site selbst ergänzen kann — vorgegebene Werte aus dem
 * Code, dazu, was die Site hinzugefügt hat. Zwei Arten:
 *
 * - `select`: fester Schlüssel je Wert (Lebenszyklus «lead», Art «call»); hinzugefügte Werte bekommen einen Schlüssel
 *   aus ihrem Text. Gespeichert wird der Schlüssel, angezeigt die Bezeichnung.
 * - `text`: Der Wert ist der Text selbst (Funktion, Abteilung, Ort) — Vorschläge, freie Eingabe bleibt möglich; was
 *   schon in Einträgen steht (`used`), erscheint von selbst unter den Vorschlägen.
 *
 * Hinzufügen darf, wer einen der Bereiche (`areas`) bearbeiten darf; die Liste gilt, solange eines ihrer Module für die
 * Site an ist (ohne Module immer).
 *
 * @phpstan-type Value array{value: string, label: string, group?: string|null, fill?: array<string, string>}
 */
final class ChoiceList
{
    public const SELECT = 'select';

    public const TEXT = 'text';

    /** @var (Closure(): list<Value>)|null */
    private ?Closure $values = null;

    /** @var list<Closure(Site): iterable<mixed>> */
    private array $used = [];

    /** @var (Closure(): list<string>)|null */
    private ?Closure $groups = null;

    /** @var (Closure(Site): list<Value>)|null */
    private ?Closure $source = null;

    /** @var (Closure(Site, string, ?int): Value)|null */
    private ?Closure $create = null;

    /** @var (Closure(): string)|null */
    private ?Closure $manage = null;

    /**
     * @param  Closure(): string  $label
     * @param  list<string>  $areas
     * @param  list<string>  $modules
     */
    private function __construct(public readonly string $key, public readonly string $kind, private readonly Closure $label, private array $areas,
        private array $modules) {}

    /**
     * @param  Closure(): string  $label  Name der Liste in der Sprache der Oberfläche («CRM › Lebenszyklus»)
     * @param  string|list<string>  $areas  Bereiche, deren Recht «bearbeiten» das Hinzufügen erlaubt
     * @param  string|list<string>  $modules  Module, mit denen die Liste gilt
     */
    public static function select(string $key, Closure $label, string|array $areas, string|array $modules = []): self
    {
        return new self($key, self::SELECT, $label, (array) $areas, (array) $modules);
    }

    /**
     * @param  Closure(): string  $label
     * @param  string|list<string>  $areas
     * @param  string|list<string>  $modules
     */
    public static function text(string $key, Closure $label, string|array $areas, string|array $modules = []): self
    {
        return new self($key, self::TEXT, $label, (array) $areas, (array) $modules);
    }

    /**
     * Eine Liste, die mehrere Plugins teilen (Funktion einer Kontaktperson in CRM und Finanzbuchhaltung): Jedes meldet
     * seinen Bereich und sein Modul dazu.
     */
    public function sharedWith(string $area, string $module): self
    {
        $this->areas = array_values(array_unique([...$this->areas, $area]));
        $this->modules = array_values(array_unique([...$this->modules, $module]));

        return $this;
    }

    /** @return list<string> */
    public function areas(): array
    {
        return $this->areas;
    }

    /** @return list<string> */
    public function modules(): array
    {
        return $this->modules;
    }

    /** @param  Closure(): list<Value>  $values  vorgegebene Werte in der Sprache der Oberfläche, in Anzeigereihenfolge */
    public function values(Closure $values): self
    {
        $this->values = $values;

        return $this;
    }

    /** @param  Closure(): list<string>  $groups  Reihenfolge der Gruppen (ohne Angabe: in der Reihenfolge des Auftretens) */
    public function groups(Closure $groups): self
    {
        $this->groups = $groups;

        return $this;
    }

    /** @param  Closure(Site): iterable<mixed>  $used  Werte, die in Einträgen der Site stehen (nur `text`): Texte oder Werte mit `fill` */
    public function used(Closure $used): self
    {
        $this->used[] = $used;

        return $this;
    }

    /**
     * Werte, die das Plugin selbst führt (Kategorien der Dokumente mit Übersetzungen und Kennung): Es liefert sie,
     * legt neue an und pflegt sie auf seiner eigenen Seite; Verwaltung › Auswahllisten verweist dorthin.
     *
     * @param  Closure(Site): list<Value>  $options
     * @param  Closure(Site, string, ?int): Value  $create  Site, Bezeichnung, Person → neuer Wert
     * @param  Closure(): string  $manage  Adresse der Seite, auf der das Plugin die Werte pflegt
     */
    public function external(Closure $options, Closure $create, Closure $manage): self
    {
        $this->source = $options;
        $this->create = $create;
        $this->manage = $manage;

        return $this;
    }

    public function isExternal(): bool
    {
        return $this->source !== null;
    }

    /** @return list<Value> */
    public function externalValues(Site $site): array
    {
        return $this->source !== null ? ($this->source)($site) : [];
    }

    /** @return Value */
    public function createExternal(Site $site, string $label, ?int $userId): array
    {
        return $this->create !== null ? ($this->create)($site, $label, $userId) : ['value' => $label, 'label' => $label];
    }

    public function manageUrl(): ?string
    {
        return $this->manage !== null ? ($this->manage)() : null;
    }

    public function label(): string
    {
        return ($this->label)();
    }

    /** @return list<Value> */
    public function builtin(): array
    {
        return $this->values !== null ? ($this->values)() : [];
    }

    /** @return list<string> */
    public function groupOrder(): array
    {
        return $this->groups !== null ? ($this->groups)() : [];
    }

    /** @return list<Value> */
    public function usedValues(Site $site): array
    {
        $out = [];
        foreach ($this->used as $used) {
            foreach ($used($site) as $value) {
                $text = is_string($value) ? trim($value) : (is_array($value) && is_string($value['value'] ?? null) ? trim($value['value']) : '');
                if ($text === '' || isset($out[$text])) {
                    continue;
                }
                $fill = is_array($value) && is_array($value['fill'] ?? null) ? array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $value['fill']) : [];
                $out[$text] = ['value' => $text, 'label' => $text] + ($fill !== [] ? ['fill' => $fill] : []);
            }
        }

        return array_values($out);
    }
}
