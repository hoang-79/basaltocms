<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

/**
 * Das Register der Besucherrechte (CR-006 FO-K3): Module melden ihre Rechte beim Booten an — mit Bezeichnung, Gruppe
 * für die Matrix, Vorgaben je Systemgruppe und, falls das Recht je Objekt überschreibbar ist, dem Objekttyp
 * (`forum_node`). In der Konfiguration statt statisch (Octane).
 *
 * @phpstan-type Definition array{label: string, group: string, help: string, defaults: array<string, int>, subject: string|null, writes: bool}
 */
final class VisitorPermissions
{
    public const CONFIG = 'basalto.visitor_permissions';

    /**
     * @param  array<string, int>  $defaults  Systemgruppe → Wert (VisitorPermission::YES/NO/NEVER); fehlende Gruppen gelten als nicht gesetzt
     * @param  bool  $writes  ein Schreibrecht — eine Schreibsperre (TB 39) nimmt es, egal was die Gruppen sagen
     */
    public function define(string $key, string $label, string $group, array $defaults = [], ?string $subject = null, string $help = '', bool $writes = false): self
    {
        config()->set(self::CONFIG.'.'.str_replace('.', '_', $key), ['key' => $key, 'label' => $label, 'group' => $group, 'help' => $help, 'defaults' => $defaults, 'subject' => $subject, 'writes' => $writes]);

        return $this;
    }

    /** @return array<string, Definition> */
    public function all(): array
    {
        $out = [];
        foreach ((array) config(self::CONFIG, []) as $definition) {
            if (is_array($definition) && is_string($definition['key'] ?? null)) {
                $out[$definition['key']] = ['label' => (string) $definition['label'], 'group' => (string) $definition['group'], 'help' => (string) ($definition['help'] ?? ''),
                    'defaults' => is_array($definition['defaults'] ?? null) ? $definition['defaults'] : [], 'subject' => is_string($definition['subject'] ?? null) ? $definition['subject'] : null,
                    'writes' => (bool) ($definition['writes'] ?? false)];
            }
        }

        return $out;
    }

    /** @return Definition|null */
    public function get(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /** @return array<string, Definition> Rechte, die sich für diesen Objekttyp überschreiben lassen */
    public function forSubject(string $subject): array
    {
        return array_filter($this->all(), static fn (array $d): bool => $d['subject'] === $subject);
    }
}
