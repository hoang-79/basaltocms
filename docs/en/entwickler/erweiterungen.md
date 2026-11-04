# Modules and plugins

Basalto has **one** extension interface for both: modules come with Basalto (`modules/*`,
`basalto/module-*`), plugins come from third parties — technically they are built the same way. An
extension addresses the core only through public contracts: the backend interface, the Lava registry, domain events and
container tags. Whatever a module needs goes into the contract — no access to internal classes.

![A module connects to the core through four contracts: backend, Lava registry, events and container tags](bilder/modul-vertraege.svg)

Two examples under `modules/`:

- `modules/example` — the smallest module: a backend page, a menu item, a migration and
  Lava extensions.
- `modules/comments` — a real module: Lava tag, frontend route, moderation with a count on
  the menu, privacy and clean-up run.

## Structure of a package

```text
modules/example/
├── composer.json                 package, service provider, manifest
├── src/ExampleServiceProvider.php
├── src/Http/Controllers/…
├── database/migrations/…
├── lang/de/backend.php, lang/en/backend.php, lang/en.json …
└── resources/js/pages/Hello.vue  Inertia page “example::Hello”
```

### `composer.json` with manifest

```json
{
    "name": "basalto/module-example",
    "type": "library",
    "require": { "php": "^8.4", "basalto/core": "*" },
    "autoload": { "psr-4": { "Basalto\\Modules\\Example\\": "src/" } },
    "extra": {
        "laravel": {
            "providers": ["Basalto\\Modules\\Example\\ExampleServiceProvider"]
        },
        "basalto": {
            "module": "example",
            "name": "Beispiel",
            "api": 1,
            "lava": "example",
            "switchable": true
        }
    }
}
```

| Key in `extra.basalto` | Meaning |
| --- | --- |
| `module` | Handle of the module — lowercase letters, digits, `-` and `_`; also the prefix in Lava and the namespace of the Inertia pages |
| `name` | Display name |
| `api` | Version of the extension interface, currently `1` |
| `lava` | Namespace in Lava (same as the handle) |
| `switchable` | Whether the module can be switched on and off per site |
| `requires` | Handles of other modules it needs (e.g. the marketplace `["licensing"]`) — information for people and the marketplace; the core does not enforce it yet |

Laravel finds the service provider via package discovery (`extra.laravel.providers`). The `composer.json` in the
installation's main folder includes all folders under `modules/*` as local packages (`path`).

`Backend::module()` reads the manifest and stores it (`Backend::modules()`). Whether a module is switchable is determined
by the `switchable` parameter of the call — the entry in the manifest is currently informational only (for the
marketplace).

## Service provider

Everything happens in `boot()`. The example module in full:

```php
final class ExampleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $root = dirname(__DIR__);
        $this->loadTranslationsFrom($root.'/lang', 'example');
        $this->loadJsonTranslationsFrom($root.'/lang');
        $this->loadMigrationsFrom($root.'/database/migrations');

        $backend = $this->app->make(Backend::class);
        $backend->pages('example', $root.'/resources/js/pages');
        $backend->module('example', 'example::backend.title', composerJson: $root.'/composer.json');
        $backend->menu('example::backend.title', 'basalto.example', 50, Backend::MODE_CONTENT, module: 'example', icon: 'module');
        $backend->routes(static function (): void {
            Route::get('example', HelloController::class)->name('example');
        });

        $this->app->extend(Registry::class, static function (Registry $registry): Registry {
            $registry->module('example')
                ->function('example.greeting', static fn (mixed $name): string => 'Grüezi '.(string) $name)
                ->tag('example.cart', static function (array $args, array $named): string { /* … */ })
                ->fieldType('example.price', ['input' => 'number', 'step' => 0.05, 'currency' => 'CHF']);

            return $registry;
        });
    }
}
```

## Backend interface: `Basalto\Core\Backend\Backend`

A singleton in the container (`$this->app->make(Backend::class)`).

| Method | Signature | Purpose |
| --- | --- | --- |
| `module` | `module(string $handle, string $label, bool $switchable = true, ?string $composerJson = null, bool $enabledByDefault = true): self` | Register a module; `$label` is a translation key; `$composerJson` supplies the manifest; `$enabledByDefault` false means off by default |
| `menu` | `menu(string $label, string $route, int $order = 100, string $mode = self::MODE_CONTENT, ?string $module = null, ?string $icon = null): self` | Menu item; `$route` is a route name, `$mode` one of `MODE_CONTENT` (Content), `MODE_SETUP` (Setup), `MODE_ADMIN` (Administration). With `$module`, the item disappears when the module is switched off for the site |
| `count` | `count(string $route, Closure $count): self` | Count on the menu item of this route, per site: `fn (Site $site): int` |
| `pages` | `pages(string $namespace, string $directory): self` | Directory with Vue pages under a namespace |
| `routes` | `routes(Closure $routes): void` | Routes under the backend path (`basalto.backend.path`, default `admin`) with the backend middleware (`web`, `auth`, active account, site selection, Inertia) and the name prefix `basalto.` |
| `moduleEnabled` | `moduleEnabled(string $handle, Site $site): bool` | Is the module switched on for this site? |
| `modules`, `menuItems`, `menuByMode`, `counts`, `pageNamespaces`, `path`, `middleware`, `vite` | — | Read access for the core |

An example of `count()` from the comments module — the number of pending comments on the menu:

```php
$backend->count('basalto.comments.index', static fn (Site $site): int => Comment::query()->withoutGlobalScopes()
    ->where('site_id', $site->id)->where('status', CommentStatus::Pending->value)->count());
```

### Routes

`routes()` adds the backend path and the name prefix: `Route::get('example', …)->name('example')` becomes
`/admin/example` with the name `basalto.example` — matching the menu item above. Routes outside the backend (such as
submitting comments from the frontend) are created by the module itself:

```php
Route::post('comments/{entry}', CommentSubmissionController::class)->whereNumber('entry')
    ->middleware(['web', 'basalto.site'])->withoutMiddleware(ValidateCsrfToken::class)->name('basalto.comments.submit');
```

### Areas and rights

Every backend route belongs to an **area** with a group (content, setup, administration). The module registers its
areas in `boot()` and maps its routes by prefix; individual routes can require a higher level:

```php
app(Areas::class)->register('members', 'Mitglieder', Areas::CONTENT, 'members')
    ->map('basalto.members.', 'members')
    ->route('basalto.members.settings', 'members', Level::All);
```

Without an explicit level, GET requires “view”, DELETE “delete” and everything else “edit”. The `GuardAreaAccess`
middleware checks every request, the menu hides entries without the right. Routes without a mapping require
“Settings · all”, and the `AreaCoverageTest` reports them. In code you ask
`Gate::allows('area', [$site, 'members', Level::Delete])` or `Access::allows()`; languages are checked by
`Access::authorizeLocale()`. There is no need any more to “borrow” another ability (such as the site’s `update`). How
operators set up roles: [Roles and rights](../betrieb/rollen.md).

### Inertia pages with a namespace

A controller renders `Inertia::render('example::Hello', [...])`; the part before `::` is the namespace from `pages()`,
the part after it is the path of the page without `.vue`. The core uses `core::…`.

```php
final class HelloController
{
    public function __invoke(Request $request): Response
    {
        $greeting = (new Renderer(app(Registry::class)))->render('{{ example.greeting(name) }}', ['name' => $request->user()->name ?? 'Gast']);

        return Inertia::render('example::Hello', ['greeting' => $greeting]);
    }
}
```

The core builds the pages of all bundled modules into its bundle: `packages/core/resources/js/backend.ts` looks for
`modules/<namespace>/resources/js/pages/<Page>.vue`. The namespace must therefore match the folder name under
`modules/`. Third-party plugins therefore ship no Vue pages of their own: they describe their administration in PHP
and the core draws it — see [Backend pages from a description](#backend-pages-from-a-description).

### Switchable per site

To switch switchable modules on and off: Administration › Sites › Edit site › “Modules” tab. The setting is stored in
`sites.settings.modules.<handle>`; without an entry, the module's default applies — usually switched on, switched off by default with `module(…, enabledByDefault: false)` (as with Licence sales and Marketplace, which only run on basaltocms.com). Menu items with `module:`
disappear automatically. **The module checks everything else itself** with `moduleEnabled()` — this is how the comments
module does it in the tag output, in submission and in moderation:

```php
abort_if($site === null || ! $this->backend->moduleEnabled('comments', $site), 404);
```

When switched off, the data remains stored.

## Backend pages from a description

A plugin builds its administration without shipping any JavaScript: the controller returns a description of the page
(`Basalto\Core\Backend\Schema\AdminPage`), and the core draws it with its own components — key figures, notices,
text, lists, forms, buttons. Every page thus looks like the rest of the backend, works with keyboard and screen reader
and stays valid when the core is updated. Validation and saving remain ordinary Laravel code.

```php
use Basalto\Core\Backend\Schema\{Action, AdminPage, Column, Form, FormField, Row, Stat, Table};

public function index(Request $request): AdminPage
{
    $q = trim((string) $request->query('q', ''));
    $notes = Note::query()->when($q !== '', fn ($query) => $query->whereLike('text', '%'.$q.'%'))
        ->orderByDesc('id')->paginate(20)->withQueryString();

    return AdminPage::make(__('Notizen'))
        ->crumb(__('Beispiel › Notizen'))
        ->stats([Stat::make(__('Notizen'), Note::query()->count())])
        ->form(Form::make(route('basalto.example.notes.store'))->title(__('Neue Notiz'))->submit(__('Anlegen'))
            ->fields([FormField::textarea('text', __('Text'))->required()->rows(3)]))
        ->table(Table::make('notizen')
            ->columns([Column::text('text', __('Text')), Column::date('created', __('Angelegt'))])
            ->rows($notes->items(), fn (Note $note): Row => Row::make($note->id)
                ->cell('text', $note->text)
                ->cell('created', $this->dates->dateTime($note->created_at))
                ->url(route('basalto.example.notes.edit', ['note' => $note->id]))
                ->action(Action::delete(__('Löschen'), route('basalto.example.notes.destroy', ['note' => $note->id]))
                    ->confirm(__('Diese Notiz löschen?'))->variant('danger')))
            ->search($q, __('Notizen durchsuchen'))
            ->paginate($notes)
            ->bulk(Action::bulk('delete', __('Löschen'), route('basalto.example.notes.bulk'))->confirm(__('Die gewählten Notizen löschen?')))
            ->empty(__('Noch keine Notizen'), __('Legen Sie oben die erste an.')));
}
```

The complete example with editing, deleting and a bulk action is in the example module
(`modules/example/src/Http/Controllers/NotesController.php`, page `/admin/example/notizen`).

| Component | Call | Purpose |
| --- | --- | --- |
| `AdminPage` | `make($title)`, `crumb()`, `lead()`, `action()`, `stats()`, `notice($text, $tone, $title)`, `text($text, $title)`, `table()`, `form()` | The page; sections appear in the order of the calls |
| `Stat` | `make($label, $value)`, `hint()`, `tone()` | Key figure at the top of the page; tone `neutral`, `success`, `warning`, `danger` |
| `Table` | `make($key)`, `title()`, `columns()`, `rows($items, fn)`, `search($value, $placeholder, $param)`, `filter()`, `paginate($paginator)`, `bulk(...)`, `empty($title, $text)` | List with search, filters, pages and bulk actions |
| `Column` | `text`, `mono`, `number`, `money`, `date`, `badge`, `link` | Column; `number` and `money` are right-aligned |
| `Row` | `make($id)`, `cell()`, `badge($key, $text, $tone)`, `link($key, $text, $url)`, `url()`, `action()` | Row; with `url()` the first column becomes a link to the record |
| `Filter` | `make($param, $label, $value)`, `option($value, $label, $count)` | Choice above the list, as a query parameter in the address |
| `Form` | `make($url, $method)`, `title()`, `fields()`, `values()`, `autosave()`, `submit()` | Form; with `autosave()` it saves itself after every pause, otherwise with the button |
| `FormField` | `text`, `email`, `url`, `number`, `password`, `textarea`, `select($name, $label, $choices)`, `checkbox`, `date`, `time`, `datetime`, `color`, `richtext`, `file($name, $label, $accept)`; plus `help()`, `placeholder()`, `required()`, `min()`, `max()`, `step()`, `rows()`, `mono()`, `onLeave()`, `half()` | Field; with autosave, `password` is only saved when the field is left |
| `Action` | `link`, `post`, `put`, `delete`, `bulk($key, $label, $url)`; plus `confirm($question)`, `variant()`, `with($data)` | Button; with `confirm()` the core asks first |

What matters:

- **Pass translated texts.** The description contains finished texts; use `__()` with the keys from the plugin's
  `lang/` (DE, EN, FR, IT).
- **Errors at the field:** the controller validates as usual (`$request->validate()`); the core shows the messages at
  the matching field. Confirmations go back as `back()->with('status', …)`.
- **Bulk actions** send `action` (the key from `Action::bulk`) and `ids` to the given address — the controller checks
  both and reports how many entries were affected.
- **Autosave** (`Form::autosave()`) is meant for existing records: after a pause the core sends the fields with the
  form's method (usually `put`) and shows at the field that it has been saved. Use a form with a button for creating.
- **Rights** apply as for every route ([Areas and rights](#areas-and-rights)); the controller leaves out buttons a user
  may not use.

## Mounted pages: a page belongs to the module

A module can take over a page of the page tree together with every address below it — this is how the forum and the
“Events” plugin work. The editors create the page and choose the module under “Mount a module” in the “Expert” tab;
title, breadcrumbs, metadata and layout still come from the page and the theme.

```php
use Basalto\Core\Community\{MountHandler, MountRequest, MountResult, Mounts};

// boot() — handle, label in the choice, handler, module
app(Mounts::class)->register('events', 'Veranstaltungen', EventsMount::class, 'events');

final class EventsMount implements MountHandler
{
    public function handle(MountRequest $request): MountResult
    {
        return match ($request->segments) {
            [] => MountResult::page('<section>…</section>', head: '<link rel="alternate" …>'),
            ['kalender.ics'] => MountResult::raw($ics, 'text/calendar; charset=utf-8'),
            default => MountResult::notFound(),
        };
    }
}
```

`MountRequest` gives the site, language, the page, its address (`base`) and the path parts below it (`segments`);
`url('archiv')` builds an address below the page. `MountResult::page()` puts markup in place of the introduction (with
title, further breadcrumbs, description, `head` for structured data), `raw()` answers without layout (feeds,
calendars), plus `redirect()` and `notFound()`. If the module is off for the site, the page is an ordinary page and the
addresses below it answer 404. The output centres itself (`width: min(100% - 2rem, …)`, `margin: auto`), because themes
do not frame the introduction area.

With `community: true` (only the forum), profiles and conversations also live below the page — a module without a
community leaves it out. Answers for guests come from the page cache: after a change to the module’s data, call
`app(PageCache::class)->invalidate($site->id)`.

## Extending Lava: the registry

Lava only calls what is registered (`Basalto\Lava\Runtime\Registry`, one instance per application). A module extends
the instance in the container and registers **only under its own prefix** — via `module('handle')`, which returns a
`Basalto\Lava\Runtime\ModuleRegistry`. A name without a prefix, with another module's prefix or one that is already
taken throws a `LogicException`. Themes never register anything.

```php
$this->app->extend(Registry::class, static function (Registry $registry): Registry {
    $registry->module('comments')
        ->tag('comments.list', static fn (array $args, array $named, Context $ctx): string => app(CommentsRenderer::class)->render($named, $ctx));

    return $registry;
});
```

| Method of the `ModuleRegistry` | Signature | In Lava |
| --- | --- | --- |
| `function` | `function(string $name, Closure $fn): self` — any parameters; for a filter, the first one is the value | `{{ example.greeting(name) }}`, `{{ preis \| example.money }}` |
| `tag` | `tag(string $name, Closure $fn): self` — `fn (array $args, array $named, Context $ctx): string` | `{% comments.list per: 20 %}` |
| `fieldType` | `fieldType(string $name, array $options = []): self` | `field preis: example.price` |
| `property` | `property(string $keyword, string $name): self` | Additional property, e.g. `property('type', 'example.sku')` → `example.sku:` in a `type` |
| `part` | `part(string $name, string $label, array $settings, Closure $render): self` — `fn (array $settings, array $vars, string $lang): string` | Lava part `part warenkorb: shop.cart { … }` — settings as a list or with their label in the layout editor: `['per' => 'Anzahl', 'heading' => 'Überschrift']` |

Notes:

- **Tags return HTML** and escape it themselves (`htmlspecialchars`). `$args` are the positional arguments, `$named` the
  named ones; `$ctx->get('page')` and `$ctx->get('site')` return the page's variables.
- **Errors in a function or tag** (wrong type, missing argument) become a Lava error with a line number; the theme
  never sees a PHP stack. The renderer's limits apply (time, loops, depth).
- **Field types** from modules are known to the checker (`basalto:check`), which suggests them (“`price` — meinten Sie
  `example.price`?”, i.e. did you mean `example.price`?). The options (`['input' => 'number', …]`) are stored by the
  registry (`fieldTypeOptions()`); the backend form does not evaluate them yet.
- **Language server:** registered names appear in completion and hover; the language server reads the signature from
  the closure.

## Domain events

The core fires Laravel events with a stable payload (namespace `Basalto\Core\Events`). A module listens with
`Event::listen(…)`.

| Event | Payload | When |
| --- | --- | --- |
| `EntrySaved` | `Entry $entry, EntryVersion $version` | A language version was saved (draft) |
| `VersionTransitioned` | `EntryVersion $version, EntryStatus $from, EntryStatus $to, ?string $comment` | A version changed its state in the workflow |
| `VersionPublished` | `EntryVersion $version` | A version is live |
| `MediaUploaded` | `Media $media` | A media item was uploaded |
| `MediaTrashed` | `Media $media, int $usages` | A media item was moved to the trash (with the number of entries that still embed it) |
| `MediaRestored` | `Media $media` | A media item was restored from the trash |
| `SiteCreated` | `Site $site` | A site was created |
| `SiteUpdated` | `Site $site, array $before, array $after` | A site's master data changed |
| `UserLoggedIn` | `Authenticatable $user, string $method` | Sign-in to the backend (code, link or Passkey) |

All properties are `public readonly`.

To notify people about an event — in the bell and by email, according to their settings — register a kind and call
`Notify`: see [Notifications](benachrichtigungen.md).

## Privacy: container tags

Personal data in module tables hooks into the core's data access requests, deletion and clean-up run via two container
tags. Both expect a **closure** in the container.

| Tag | Called by | Signature of the closure | Return value |
| --- | --- | --- | --- |
| `basalto.person-data` | `Basalto\Core\Privacy\PersonData` (`basalto:privacy`, profile and user administration in the backend) | `fn (string $email, bool $delete): array` | For a data access request, a list per kind (appended to the report); for deletion, a number per kind |
| `basalto.cleanup` | `basalto:cleanup` (daily at 03:00) | `fn (Settings $settings): array` | Per kind, the number of deleted records; appears in the output and the log |

This is how the comments module connects both:

```php
$this->app->instance('basalto.person-data.comments', (new CommentPersonData)(...));
$this->app->tag(['basalto.person-data.comments'], 'basalto.person-data');
$this->app->instance('basalto.cleanup.comments', CommentPersonData::cleanup(...));
$this->app->tag(['basalto.cleanup.comments'], 'basalto.cleanup');
```

`CommentPersonData::__invoke(string $email, bool $delete): array` returns `['kommentare' => [...]]` or
`['kommentare' => $anzahl]`; `CommentPersonData::cleanup(Settings $settings): array` deletes spam and deleted comments
per site after the retention period `comments.retention_days` (default 365) and returns `['Kommentare' => $anzahl]`. The
email address arrives in lowercase and without whitespace; the search covers all sites.

## Frontend account and protected pages

The **frontend account** belongs to the core (`Basalto\Core\FrontendAccounts`). Licence sales, marketplace and members share it without depending on each other. Older names from licence sales
(`Basalto\Modules\Licensing\Accounts\Customer`, `CustomerAuth`, `CustomerLogin`, `CustomerLoginCode`,
`Frontend\LoginForm`, `Http\CustomerLoginController`, `Http\Middleware\…`, `Notifications\CustomerLoginCodeNotification`)
remain as `class_alias` and are deprecated; new code uses the core names. An alias is the same class (`instanceof`
holds for both names) — only comparisons by the class name as a string (such as
`Notification::assertSentTo(…, OldName::class)`) see the core name.

| Interface | Purpose |
| --- | --- |
| `CustomerAuth::useFor('module', ?Policy::class)` | The module uses the account (in `boot()`). The routes `POST /account/login`, `/account/code`, `/account/logout` and the sign-in page `GET /account/login` only respond on a site when one of the using modules is on. |
| `RegistrationPolicy::opensRegistration(Site)` | May the sign-in create new accounts on the site? Without a policy, yes. If none of the enabled modules allows it, codes only go to addresses with an account (the response to the browser stays the same). |
| `CustomerAuth::current()` · `::available(Site)` · `::MIDDLEWARE` (`basalto.customer`) | Who is signed in (only accounts of the request’s site), whether the account exists on the site, middleware for routes that need an account |
| `LoginForm::render($request, $lang, $path, $intro, $purpose)` | The sign-in form for your own tags; `$purpose` (e.g. `members`) comes back after the code as `CustomerSignedIn::$purpose` |
| Event `Events\CustomerSignedIn` (`customer`, `site`, `purpose`) | After the correct code — the members module uses it to create the membership the first time |

**Areas in the customer account:** the tag `{% account %}` (Lava part “Customer account”, settings `buy`, `entry`, `catalog`, `start`) shows the whole account: a compact header with greeting, the areas as navigation on the left (number, hint dot), one area at a time on the right — first the overview with “Up next”, a tile per area and “Recently”. Each area has the anchor `#konto-<key>`; an anchor anywhere inside an area (`#lizenz-12`) opens that area. A module contributes an area by registering a class in `boot()` — `app(AccountAreas::class)->add(MyArea::class)` — that implements `Basalto\Core\FrontendAccounts\Hub\AccountArea`:

| Method | Purpose |
| --- | --- |
| `key()` · `position()` | Anchor `#konto-<key>` and order (sites 10, licences 20, installations 30, marketplace 40, author account 45, community 50, invoices 60, profile 90) |
| `pitch($page)` | For guests: icon, title and one sentence (`AreaPitch`) — or `null` |
| `summary($page)` | Signed in: `AreaSummary` with label, icon, count, tone (`lava`, `warn`), tasks (`AreaTodo`), events (`AreaEvent`), buttons (`AreaAction`) and notices — `null` hides the area |
| `render($page, $summary)` | The content of the section; the account sets frame, heading and anchor |

The area is created afresh for each output and may keep data between `summary()` and `render()`. `AccountPage` provides site, language, path, request, signed-in account and `t()` for texts, `Icons::svg()` the account’s icons. Markup building blocks: `konto-zeilen` with `konto-zeile`, `konto-sechseck`, `konto-chip`, `konto-knopf`, `konto-leer`, `konto-klappe`.

**Protected pages:** The core knows the protection (tab “Access”, `access.protected`, inherited via
`access.children`; `Pages::isProtected()`), shows protected pages in navigation and lists only to those allowed and
never in sitemap, search index and feeds, and answers guests with 401 (signed-in users without access with 403). Who may
see them is decided by a module via `PageAccess::useGate(Gate::class)` with the contract
`Basalto\Core\Content\ProtectedPageGate` (`enabled`, `adminUrl`, `allows`, `denied`, `login`); without a module only
the site’s backend editors see them. A module’s own lists ask `app(PageAccess::class)->mayView($entry)`.

**Data protection:** `PersonData` calls the modules (`basalto.person-data`) first and the core’s frontend accounts
**afterwards** — so a module still finds its data via the account’s email when deleting; it does not have to anonymise
the account.

## Installed without Composer

Installations from the web installer have no Composer: there, Basalto installs plugins from the marketplace itself
([Installing a plugin](../marktplatz/installieren.md#installing-a-plugin)). For this to work, a plugin needs:

- **`composer.json`** at the root with `type: basalto-plugin`, `extra.basalto.module` (handle) and
  `extra.laravel.providers`. Classes only via `autoload.psr-4` (plus `autoload.files` if needed) — the loader does not
  know `classmap`.
- **No libraries of its own.** Whatever `require` names must already be in the installation, in a matching version —
  such as `laravel/framework`, `dompdf/dompdf`, `sprain/swiss-qr-bill`, `league/flysystem-aws-s3-v3`, the bundled
  modules. Basalto checks `require.basalto/core` (e.g. `^1.3`) against the running version; if something does not fit,
  it does not install and names the reason. The marketplace checks the same rules on submission.
- **Migrations under `database/migrations`**, registered with `loadMigrationsFrom()`: when removing with “Also delete the
  data”, Basalto resets exactly this folder. Migrations therefore need a `down()`.
- **No Vue pages of its own:** the core bundle only knows the bundled modules. A plugin's administration is built
  from [Backend pages from a description](#backend-pages-from-a-description).
- **Nothing risky at start-up:** Basalto registers the plugin in the running request, migrates and then checks whether
  the site still starts in a fresh process (`/up/live`). If a provider throws at start-up, the plugin stays off; if it
  throws later in a request, only the plugin is missing and the administration shows the reason.

- **PHPStan without Larastan:** the marketplace check analyses the code with PHPStan level 5, but without the Laravel
  extension. Write Eloquent queries as statements (`$query->where(…);` and then `$query->get()`) instead of long
  chains, and call a method of your own instead of scopes (`scopeVisible()`) — otherwise the check reports forwarded
  methods as unknown.

Basalto stores the plugin under `storage/app/plugins/<vendor>/<package>` and keeps the list
`storage/app/plugins/plugins.php` itself; from it, the core registers the PSR-4 paths with the autoloader at start-up
and registers the providers.

**While developing**, put the plugin into a Git working copy of Basalto under `plugins/<name>/`: there Basalto loads
it at start-up with the same rules as an installed one — in development and in the tests (can be switched off with
`BASALTO_PLUGINS_WORKSHOP=false`). A release never contains the `plugins/` folder.

**Try it out yourself** — in an installation from the web installer, with the ZIP you want to submit:

```sh
php artisan basalto:plugin install --archiv=path/to/plugin.zip
php artisan basalto:plugin check vendor/package
php artisan basalto:plugin remove vendor/package --daten-loeschen
```

`--archiv` installs without the catalogue and without the marketplace signature, otherwise as in the backend (form,
compatibility, backup, migrations, start probe). `check` calls every GET page of the plugin without required
parameters — signed in as the owner of the default site, with `--benutzer=<e-mail>` as someone else — and fails as soon
as a page answers with a server error (`--json` for machine-readable output). After removing with `--daten-loeschen`, no
table of the plugin may be left. The marketplace takes exactly these steps in the **trial run** in its sandbox before
it approves a plugin.

## Obligations of an extension

What every extension must observe:

1. **Site scope** on every table containing a site's content: column `site_id` and, in the model, the trait
   `Basalto\Core\Sites\BelongsToSite` (global scope on the current site, `site_id` on creation) — plus a test that
   a second site sees nothing.
2. **Translations** for the interface (DE, EN, IT, FR) under `lang/`; menu labels are translation keys. The JSON
   translations of all modules apply together: include only your own keys — a key the core already translates (such as
   “Ort” or “Stand”) would be overwritten for the whole backend.
3. **Migrations** in the package (`loadMigrationsFrom`), never in the core.
4. **Personal data** connected to `basalto.person-data` and, if there are retention periods, to `basalto.cleanup`.
5. **Export:** every table with `site_id` goes along with the site export or stays behind with a reason — otherwise a
   test in the core fails. In the provider’s `boot()`: `app(ModuleTables::class)->export('events')` for a simple table
   (its rows refer to other data only through `site_id`; the import creates them for the new site if the module is
   installed there) or `app(ModuleTables::class)->exclude('table', 'reason')` (`Basalto\Core\Transfer\ModuleTables`).

## Next

- [Lava — the theme language](lava.md)
- [Notifications](benachrichtigungen.md)
- [REST API](api.md)
