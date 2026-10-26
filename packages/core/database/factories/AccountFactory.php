<?php

declare(strict_types=1);

namespace Basalto\Core\Database\Factories;

use Basalto\Core\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Account> */
final class AccountFactory extends Factory
{
    protected $model = Account::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['name' => fake()->company(), 'plan' => Account::PLAN_DOWNLOAD, 'status' => 'active'];
    }
}
