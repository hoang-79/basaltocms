<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;

/**
 * Prüft Besucherrechte (CR-006 FO-K3) nach dem Muster von WoltLab:
 *
 * 1. Je Gruppe der Person gilt der Wert des genauesten Objekts in der Kette (Forum → Elternforum → … → global), sonst
 *    die Vorgabe des Rechts für diese Systemgruppe; eine Gruppe ohne Wert zählt nicht.
 * 2. «Nie» in irgendeiner Gruppe verbietet, sonst erlaubt ein «Ja» in irgendeiner Gruppe, sonst nein.
 * 3. Eine Schreibsperre (TB 39) nimmt alle Schreibrechte; eine Sperre macht die Person zum Gast ({@see VisitorGroups::of}).
 *
 * Die gesetzten Werte einer Site lädt es einmal je Anfrage (scoped, Octane-sicher).
 */
final class VisitorAccess
{
    /** @var array<int, array<int, array<string, array<string, int>>>> Site → Gruppe → Recht → «typ:id» → Wert */
    private array $values = [];

    public function __construct(private readonly VisitorGroups $groups, private readonly VisitorPermissions $permissions, private readonly Sanctions $sanctions) {}

    /**
     * @param  list<array{0: string, 1: int}>  $chain  Objekte vom genauesten zum allgemeinsten, z. B. [['forum_node', 7], ['forum_node', 2]]
     */
    public function allows(Site $site, ?Customer $customer, string $permission, array $chain = []): bool
    {
        if ($customer !== null && ($this->permissions->get($permission)['writes'] ?? false) && $this->sanctions->isMuted($customer)) {
            return false;
        }
        $allowed = false;
        foreach ($this->groups->of($site, $customer) as $group) {
            $value = $this->resolve($site, $group, $permission, $chain)['value'];
            if ($value === VisitorPermission::NEVER) {
                return false;
            }
            $allowed = $allowed || $value === VisitorPermission::YES;
        }

        return $allowed;
    }

    /**
     * Der wirksame Wert einer Gruppe und woher er kommt — für die Matrix im Extranet («geerbt von …»).
     *
     * @param  list<array{0: string, 1: int}>  $chain
     * @return array{value: int|null, from: string, subject: array{0: string, 1: int}|null}
     */
    public function resolve(Site $site, VisitorGroup $group, string $permission, array $chain = []): array
    {
        $set = $this->load($site)[$group->id][$permission] ?? [];
        foreach ($chain as $subject) {
            $key = $subject[0].':'.$subject[1];
            if (array_key_exists($key, $set)) {
                return ['value' => $set[$key], 'from' => 'subject', 'subject' => $subject];
            }
        }
        if (array_key_exists(':0', $set)) {
            return ['value' => $set[':0'], 'from' => 'global', 'subject' => null];
        }
        $default = $group->key === null ? null : ($this->permissions->get($permission)['defaults'][$group->key] ?? null);

        return ['value' => is_int($default) ? $default : null, 'from' => 'default', 'subject' => null];
    }

    /**
     * Einen Wert setzen oder (null) entfernen — global oder für ein Objekt.
     *
     * @param  array{0: string, 1: int}|null  $subject
     */
    public function set(Site $site, VisitorGroup $group, string $permission, ?int $value, ?array $subject = null): void
    {
        $where = ['site_id' => $site->id, 'visitor_group_id' => $group->id, 'permission' => $permission, 'subject_type' => $subject[0] ?? '', 'subject_id' => $subject[1] ?? 0];
        if ($value === null) {
            VisitorPermission::query()->withoutGlobalScopes()->where($where)->delete();
        } else {
            VisitorPermission::query()->withoutGlobalScopes()->updateOrCreate($where, ['value' => max(-1, min(1, $value))]);
        }
        unset($this->values[$site->id]);
    }

    public function forget(): void
    {
        $this->values = [];
        $this->groups->forget();
        $this->sanctions->forget();
    }

    /** @return array<int, array<string, array<string, int>>> */
    private function load(Site $site): array
    {
        if (! isset($this->values[$site->id])) {
            $out = [];
            foreach (VisitorPermission::query()->withoutGlobalScopes()->where('site_id', $site->id)->get(['visitor_group_id', 'permission', 'subject_type', 'subject_id', 'value']) as $row) {
                $out[$row->visitor_group_id][$row->permission][$row->subject_type.':'.$row->subject_id] = $row->value;
            }
            $this->values[$site->id] = $out;
        }

        return $this->values[$site->id];
    }
}
