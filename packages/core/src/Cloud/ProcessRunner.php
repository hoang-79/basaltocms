<?php

declare(strict_types=1);

namespace Basalto\Core\Cloud;

use Symfony\Component\Process\Process;

final class ProcessRunner implements CommandRunner
{
    public function run(array $command, array $environment = []): array
    {
        $process = new Process($command, base_path(), $environment, null, 120);
        $process->run();

        return ['exit' => (int) $process->getExitCode(), 'output' => $process->getOutput().$process->getErrorOutput()];
    }
}
