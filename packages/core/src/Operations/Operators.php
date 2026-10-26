<?php

declare(strict_types=1);

namespace Basalto\Core\Operations;

use Basalto\Core\Accounts\AccountRole;
use Basalto\Core\Models\Account;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Betreiber der Installation (B-09): Besitzer und Verwaltung des Installationskontos — des ersten Kontos, das der
 * Installer anlegt (`Account::installation()`). Pulse und der Fehleralarm zeigen Zahlen aller Sites der Installation;
 * Betreiber oder Administrator einer einzelnen Site sehen sie darum nicht, auch nicht Kunden-Konten auf der SaaS.
 * Deaktivierte Konten zählen nicht.
 */
final class Operators
{
    public static function allows(?Authenticatable $user): bool
    {
        if ($user === null || ($user instanceof Model && $user->getAttribute('deactivated_at') !== null)) {
            return false;
        }
        $role = self::account()?->roleOf($user);

        return $role === AccountRole::Owner || $role === AccountRole::Admin;
    }

    /**
     * Adressen für den Fehleralarm: Besitzer und Verwaltung des Installationskontos, ohne deaktivierte.
     *
     * @return list<string>
     */
    public static function emails(): array
    {
        $account = self::account();
        if ($account === null) {
            return [];
        }

        $users = $account->users();
        $column = static fn (string $name): string => $users->getRelated()->qualifyColumn($name);

        return array_values($users
            ->wherePivotIn('role', [AccountRole::Owner->value, AccountRole::Admin->value])
            ->whereNull($column('deactivated_at'))
            ->orderBy($column('id'))
            ->pluck($column('email'))
            ->filter(static fn (mixed $email): bool => is_string($email) && $email !== '')
            ->all());
    }

    /** Das Installationskonto, ohne es anzulegen (anders als `Account::installation()`). */
    private static function account(): ?Account
    {
        return Schema::hasTable('accounts') ? Account::query()->orderBy('id')->first() : null;
    }
}
