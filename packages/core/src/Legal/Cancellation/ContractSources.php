<?php

declare(strict_types=1);

namespace Basalto\Core\Legal\Cancellation;

use Basalto\Core\Models\Site;

/**
 * Register der kündbaren Verträge (CR-011 TB 59): Kern und Module melden ihre {@see ContractSource} beim Booten an,
 * danach wird nur gelesen.
 */
final class ContractSources
{
    /** @var array<string, ContractSource> */
    private array $sources = [];

    public function register(ContractSource $source): self
    {
        $this->sources[$source->key()] = $source;

        return $this;
    }

    public function get(string $key): ?ContractSource
    {
        return $this->sources[$key] ?? null;
    }

    /** @return list<ContractSource> */
    public function for(Site $site): array
    {
        return array_values(array_filter($this->sources, static fn (ContractSource $source): bool => $source->available($site)));
    }
}
