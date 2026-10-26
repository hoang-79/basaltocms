<?php

declare(strict_types=1);

namespace Basalto\Lava;

use Basalto\Lava\Console\CheckCommand;
use Basalto\Lava\Console\ImportCommand;
use Basalto\Lava\Console\LspCommand;
use Basalto\Lava\Runtime\Registry;
use Illuminate\Support\ServiceProvider;

/** Registriert die Registrierung (eine je Anwendung), Befehle und Migrationen — das Frontend liefert der Kern aus. */
final class LavaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Eine Registrierung je Anwendung: Module hängen Funktionen, Filter, Tags und Feldtypen per
        // $app->extend(Registry::class, fn (Registry $r) => $r->module('handle')->…) an (K-12, L-12).
        $this->app->singleton(Registry::class, static fn (): Registry => Registry::standard());
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class, ImportCommand::class, LspCommand::class]);
        }
    }
}
