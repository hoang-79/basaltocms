<?php

declare(strict_types=1);

namespace Basalto\Core\Operations;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Facades\Gate;
use Laravel\Pulse\Pulse;
use Throwable;

/**
 * Monitoring im Kern verdrahten (B-09, TB 10 Scheibe 6):
 *
 * - Gate `viewPulse`: das Pulse-Dashboard sehen nur Betreiber der Installation. Erst nach dem Booten aller Provider
 *   gesetzt — Pulse definiert dasselbe Gate in seinem `boot()` mit «nur lokal» und würde eine frühere Regel ersetzen.
 * - Pulse zeigt Personen ohne Gravatar (Avatar als eingebettetes Sechseck) — kein Aufruf an einen Fremddienst mit
 *   einer Prüfsumme der E-Mail-Adresse.
 * - Jede gemeldete Ausnahme zählt für den Fehleralarm (`ErrorCounter`), unabhängig davon, ob Pulse aufzeichnet.
 * - Livewire (nur für Pulse) bindet sich nicht automatisch in fremde Seiten ein.
 */
final class MonitoringSetup
{
    public static function boot(Application $app): void
    {
        // Livewire kommt nur mit Pulse und darf nie in Seiten der Site oder des Backends springen: Seine automatische
        // Einbindung merkt sich «Komponente gerendert» statisch je Prozess (Octane, Worker, Testlauf) und hängte danach
        // `livewire.js` an jede HTML-Antwort. Das Pulse-Dashboard bindet seine Skripte selbst ein.
        config()->set('livewire.inject_assets', false);

        $app->booted(static function (): void {
            Gate::define('viewPulse', static fn (?Authenticatable $user = null): bool => Operators::allows($user));
        });

        if (class_exists(Pulse::class)) {
            self::whenResolved($app, Pulse::class, static function (Pulse $pulse): void {
                $pulse->user(static fn (Authenticatable $user): array => [
                    'name' => (string) ($user->getAttribute('name') ?? ''),
                    'extra' => (string) ($user->getAttribute('email') ?? ''),
                    'avatar' => self::avatar(),
                ]);
            });
        }

        self::whenResolved($app, ExceptionHandler::class, static function (ExceptionHandler $handler): void {
            // Auch der Konsolen-Adapter von Collision (Entwicklung) kennt `reportable()` und reicht es weiter
            if ($handler instanceof Handler || method_exists($handler, 'reportable')) {
                $handler->reportable(static function (Throwable $e): void {
                    ErrorCounter::record();
                });
            }
        });
    }

    /** Wie `ServiceProvider::callAfterResolving()`: sofort, wenn schon aufgelöst (Singletons lösen danach nicht mehr aus). */
    private static function whenResolved(Application $app, string $abstract, Closure $callback): void
    {
        if ($app->resolved($abstract)) {
            $callback($app->make($abstract));

            return;
        }
        $app->afterResolving($abstract, $callback);
    }

    /** Sechseck in Basalt als Daten-URL — dieselbe Form wie die Initialen im Backend, ohne externe Ressource. */
    public static function avatar(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><path fill="#3b3f46" d="M16 1.5 29 9v14L16 30.5 3 23V9z"/></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
