<?php

declare(strict_types=1);

namespace Basalto\Core\Database\Factories;

use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EntryVersion> */
final class EntryVersionFactory extends Factory
{
    protected $model = EntryVersion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = fake()->sentence(3);

        return [
            'entry_id' => Entry::factory(),
            'locale' => 'de',
            'number' => 1,
            'status' => EntryStatus::Draft,
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'data' => ['title' => $title, 'content' => []],
        ];
    }

    public function published(): self
    {
        return $this->state(['status' => EntryStatus::Published, 'published_at' => now()]);
    }
}
