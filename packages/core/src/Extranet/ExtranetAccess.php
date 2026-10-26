<?php

declare(strict_types=1);

namespace Basalto\Core\Extranet;

use Basalto\Core\Access\Access;
use Basalto\Core\Access\Areas;
use Basalto\Core\Access\Level;
use Basalto\Core\Access\RoleDefinition;
use Basalto\Core\Access\RoleTemplates;
use Basalto\Core\Accounts\AccountRole;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\ExtranetMember;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Wer ins Extranet darf und was (CR-004 P-09, CR-005 R-12): Besitzer und Verwaltung des Installationskontos als
 * Inhaber (alles), sonst das Team mit einer Rolle aus `extranet_members` — dieselben Rollen, Bereiche und Stufen wie
 * im CMS. Kunden (SaaS-Konten, Käufer im Lizenzverkauf) haben keine Zeile und bekommen 403. Je Anfrage zwischengespeichert.
 */
final class ExtranetAccess
{
    /** @var array<string, RoleDefinition|null> */
    private array $definitions = [];

    public function __construct(private readonly Access $access, private readonly Areas $areas) {}

    public function definition(Authenticatable $user): ?RoleDefinition
    {
        $key = (string) $user->getAuthIdentifier();
        if (array_key_exists($key, $this->definitions)) {
            return $this->definitions[$key];
        }
        $account = Account::installation();
        $accountRole = $account->users()->whereKey($user->getAuthIdentifier())->first()?->getRelationValue('pivot')?->getAttribute('role');
        if (in_array($accountRole, [AccountRole::Owner->value, AccountRole::Admin->value], true)) {
            return $this->definitions[$key] = Access::fromTemplate(RoleTemplates::OWNER);
        }
        $member = ExtranetMember::query()->where('user_id', $user->getAuthIdentifier())->first();

        return $this->definitions[$key] = $member === null ? null : $this->access->resolve($member->role, $account->id);
    }

    public function isMember(Authenticatable $user): bool
    {
        return $this->definition($user) !== null;
    }

    public function allows(Authenticatable $user, string $area, Level $level = Level::View): bool
    {
        return $this->definition($user)?->level($area)->includes($level) ?? false;
    }

    /** @return array<string, int> Stufe je Bereich des Extranets */
    public function levels(Authenticatable $user): array
    {
        $levels = [];
        foreach ($this->areas->inGroup(Areas::EXTRANET) as $area) {
            $levels[$area] = $this->definition($user)?->level($area)->value ?? 0;
        }

        return $levels;
    }

    public function forget(): void
    {
        $this->definitions = [];
    }
}
