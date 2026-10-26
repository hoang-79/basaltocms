<?php

use Basalto\Core\Http\Controllers\LoginController;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        // /up und /up/live kommen aus dem Kern (K-17: Readiness mit Datenbank und Herzschlag, Liveness ohne Datenbank)
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Gäste landen immer auf der passwortlosen Anmeldung des Kerns (K-04); das Starter-Kit hat keinen Login mehr.
        $middleware->redirectGuestsTo(static fn (Request $request): string => route('basalto.login'));
        $middleware->web(append: [
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Abmelden mit verfallener Sitzung (veraltetes CSRF-Token): Wer ohnehin abgemeldet ist, landet dort, wohin die
        // Abmeldung führt — statt auf «Seite abgelaufen». Eine angemeldete Sitzung meldet so niemand von aussen ab.
        $exceptions->render(static fn (HttpException $e, Request $request) => $e->getPrevious() instanceof TokenMismatchException && $request->routeIs('basalto.logout') && ! Auth::check()
            ? LoginController::afterLogout() : null);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
