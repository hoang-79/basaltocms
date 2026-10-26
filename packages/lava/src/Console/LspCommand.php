<?php

declare(strict_types=1);

namespace Basalto\Lava\Console;

use Basalto\Lava\Lsp\LanguageService;
use Basalto\Lava\Lsp\Server;
use Basalto\Lava\Runtime\Registry;
use Illuminate\Console\Command;

/**
 * «basalto:lsp» startet den Sprachdienst für Lava (L-14): Language Server Protocol über stdin/stdout, für VS Code
 * (Erweiterung in tools/vscode-lava) und PhpStorm (LSP4IJ). Die Standardausgabe gehört dem Protokoll —
 * PHP-Meldungen gehen auf stderr.
 */
final class LspCommand extends Command
{
    protected $signature = 'basalto:lsp {--stdio : Über Standardein- und -ausgabe sprechen (Vorgabe; VS Code hängt die Option selbst an)}';

    protected $description = 'Startet den Sprachdienst für Lava (Language Server Protocol über stdio)';

    public function handle(Registry $registry): int
    {
        ini_set('display_errors', 'stderr');
        $input = fopen('php://stdin', 'rb');
        $output = fopen('php://stdout', 'wb');
        if ($input === false || $output === false) {
            fwrite(STDERR, "Standardein- oder -ausgabe nicht verfügbar.\n");

            return self::FAILURE;
        }

        return (new Server(new LanguageService($registry), $input, $output))->run();
    }
}
