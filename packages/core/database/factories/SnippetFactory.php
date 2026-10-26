<?php

declare(strict_types=1);

namespace Basalto\Core\Database\Factories;

use Basalto\Core\Models\Site;
use Basalto\Core\Models\Snippet;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Snippet> */
final class SnippetFactory extends Factory
{
    protected $model = Snippet::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $handle = fake()->unique()->slug(2);

        return [
            'site_id' => Site::factory(),
            'handle' => $handle,
            'name' => ucfirst(str_replace('-', ' ', $handle)),
            'blocks' => [['type' => 'text', 'data' => ['text' => fake()->sentence()]]],
        ];
    }
}
