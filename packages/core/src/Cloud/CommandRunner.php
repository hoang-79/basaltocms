<?php

declare(strict_types=1);

namespace Basalto\Core\Cloud;

/** Führt einen Befehl aus — echt über einen Prozess, im Test über eine Attrappe. */
interface CommandRunner
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     * @return array{exit: int, output: string}
     */
    public function run(array $command, array $environment = []): array;
}
