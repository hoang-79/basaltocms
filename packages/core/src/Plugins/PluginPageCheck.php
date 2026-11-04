<?php

declare(strict_types=1);

namespace Basalto\Core\Plugins;

use Basalto\Core\Models\Site;
use Basalto\Core\Sites\SiteRole;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Throwable;

/**
 * Ruft die Seiten eines Plugins auf (CR-018, TB 100): jede GET-Route ohne Pflicht-Parameter, deren Controller im
 * Namensraum des Plugins liegt — in dieser Anwendung, angemeldet als Inhaberin oder Inhaber der Standard-Site (oder als
 * die angegebene Person). Nur ein Serverfehler (5xx) zählt als Fehler; Umleitungen, 403 und 404 sind Antworten. Für den
 * Prüfstand-Lauf in der Sandbox und zum Ausprobieren eines eigenen Plugins.
 */
final class PluginPageCheck
{
    public function __construct(private readonly Application $app, private readonly PluginLoader $loader, private readonly Router $router, private readonly Auth $auth) {}

    /**
     * @return list<array{uri: string, status: int, error: string|null}>
     */
    public function run(string $name, ?string $email = null): array
    {
        $record = $this->loader->all()[$name] ?? throw new RuntimeException((string) __(':name wurde nicht im Backend installiert.', ['name' => $name]));
        $prefixes = array_keys($record['psr4']);
        $user = $this->user($email);
        $kernel = $this->app->make(Kernel::class);
        $results = [];
        foreach ($this->routes($prefixes) as $route) {
            if ($user !== null) {
                $this->auth->guard('web')->setUser($user);
            }
            $request = Request::create(URL::to($route->uri()), 'GET');
            try {
                $response = $kernel->handle($request);
                $status = $response->getStatusCode();
                $exception = $response->exception ?? null;
                $error = $status >= 500 ? ($exception instanceof Throwable ? $exception->getMessage() : (string) __('Antwort :status', ['status' => $status])) : null;
                $kernel->terminate($request, $response);
            } catch (Throwable $e) {
                [$status, $error] = [500, $e->getMessage()];
            }
            $results[] = ['uri' => '/'.ltrim($route->uri(), '/'), 'status' => $status, 'error' => $error === null ? null : mb_substr($error, 0, 300)];
        }

        return $results;
    }

    /**
     * GET-Routen ohne Pflicht-Parameter, deren Controller zum Plugin gehört.
     *
     * @param  list<string>  $prefixes  PSR-4-Präfixe des Plugins
     * @return list<Route>
     */
    private function routes(array $prefixes): array
    {
        $found = [];
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true) || preg_match('/\{[^}?]+\}/', $route->uri()) === 1) {
                continue;
            }
            $controller = $route->getControllerClass();
            if ($controller === null) {
                continue;
            }
            foreach ($prefixes as $prefix) {
                if (str_starts_with(ltrim($controller, '\\'), $prefix)) {
                    $found[] = $route;

                    break;
                }
            }
        }

        return $found;
    }

    private function user(?string $email): ?Authenticatable
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');
        if ($email !== null && $email !== '') {
            $user = $model::query()->where('email', $email)->first();

            return $user instanceof Authenticatable ? $user : throw new RuntimeException((string) __('Kein Benutzer mit :email.', ['email' => $email]));
        }
        $site = Site::query()->where('handle', (string) config('basalto.default_site'))->first();
        $id = $site?->users()->wherePivot('role', SiteRole::Owner->value)->orderBy('site_user.id')->value('users.id');
        $owner = $id === null ? null : $model::query()->find($id);

        return $owner instanceof Authenticatable ? $owner : null;
    }
}
