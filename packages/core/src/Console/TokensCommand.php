<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Models\Site;
use Basalto\Core\Theme\ThemeAssets;
use Illuminate\Console\Command;

/**
 * Die Design-Tokens einer Site (L-10): als CSS-Variablen wie im Kopf der Seiten oder als Tailwind-4-Theme für Themes,
 * die ihr CSS mit Tailwind bauen (`basalto:tokens --tailwind > themes/x/assets/tokens.css`).
 */
final class TokensCommand extends Command
{
    protected $signature = 'basalto:tokens {site? : Handle der Site, sonst die Standard-Site} {--tailwind : als @theme für Tailwind 4}';

    protected $description = 'Design-Tokens einer Site als CSS-Variablen oder Tailwind-Theme ausgeben';

    public function handle(ThemeAssets $assets): int
    {
        $handle = $this->argument('site') ?? config('basalto.default_site');
        $site = Site::query()->where('handle', $handle)->first() ?? Site::query()->orderBy('id')->first();
        if ($site === null) {
            $this->error('Keine Site gefunden.');

            return self::FAILURE;
        }
        $design = $assets->design($site);
        foreach (explode("\n", rtrim($this->option('tailwind') ? $design->tailwind() : $design->css(static fn (string $path): string => $path))) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
