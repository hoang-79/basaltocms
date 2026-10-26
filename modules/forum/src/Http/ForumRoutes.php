<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Http;

use Basalto\Core\Extranet\Extranet;
use Basalto\Core\Models\Site;
use Closure;
use Illuminate\Support\Facades\Route;

/**
 * Die Verwaltung des Forums an zwei Orten (CR-006 FO-28): im Extranet (`basalto.forum.*`, Basalto-Community) und im
 * Backend jeder Kunden-Site unter Verwaltung › Community (`basalto.forumadmin.*`). Dieselben Controller und Seiten;
 * die Adressen, die sie den Seiten mitgeben, folgen dem Ort der Anfrage.
 */
final class ForumRoutes
{
    public const EXTRANET = 'basalto.forum.';

    public const BACKEND = 'basalto.forumadmin.';

    public static function prefix(): string
    {
        $route = request()->route();
        $name = is_object($route) ? $route->getName() : null;

        return is_string($name) && str_starts_with($name, self::BACKEND) ? self::BACKEND : self::EXTRANET;
    }

    /** @param  array<array-key, mixed>|int|string  $parameters */
    public static function url(string $name, array|int|string $parameters = []): string
    {
        $prefix = self::prefix();

        return route($prefix.$name, $parameters);
    }

    /**
     * Verweise ins Frontend der Site: Im Extranet (anderer Host) absolut auf die Domain der Site, im Backend der Pfad.
     * Profilbilder und Seiten der Community lagen im Extranet sonst auf dessen Host und liefen ins Leere.
     *
     * @return Closure(?string): ?string
     */
    public static function front(Site $site): Closure
    {
        $absolute = Extranet::isRequest();

        return static fn (?string $path): ?string => $path === null || ! $absolute || ! str_starts_with($path, '/') ? $path : $site->publicUrl($path);
    }

    public static function inBackend(): bool
    {
        return self::prefix() === self::BACKEND;
    }

    /** Vorhandene Adresse? (Im Extranet gibt es keine Backend-Routen und umgekehrt.) */
    public static function has(string $name): bool
    {
        return Route::has(self::prefix().$name);
    }
}
