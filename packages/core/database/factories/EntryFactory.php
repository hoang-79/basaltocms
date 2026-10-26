<?php

declare(strict_types=1);

namespace Basalto\Core\Database\Factories;

use Basalto\Core\Models\Entry;
use Basalto\Core\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Entry> */
final class EntryFactory extends Factory
{
    protected $model = Entry::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'type' => 'page',
        ];
    }
}
