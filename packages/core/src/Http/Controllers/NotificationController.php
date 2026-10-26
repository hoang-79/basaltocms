<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Http\Middleware\SelectBackendSite;
use Basalto\Core\Models\Site;
use Basalto\Core\Notifications\NotificationCenter;
use Basalto\Core\Notifications\NotificationSettings;
use Basalto\Core\Notifications\NotificationType;
use Basalto\Core\Notifications\NotificationTypes;
use Basalto\Core\Support\Preferences;
use Basalto\Core\Support\Text;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Glocke und Center (TB 27) — im Backend und, auf dem Host des Extranets, für das Team des Herstellers: Liste mit
 * Filter, als gelesen markieren, entfernen, öffnen (markiert gelesen und wechselt wenn nötig die Site). Dazu die
 * Einstellungen im Profil (Reiter «Benachrichtigungen»).
 */
final class NotificationController
{
    public function __construct(private readonly NotificationCenter $center, private readonly NotificationTypes $types) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $scope = NotificationCenter::scope($request);
        $filter = $request->query('filter') === 'ungelesen' ? 'unread' : 'all';
        $group = is_string($request->query('gruppe')) ? (string) $request->query('gruppe') : null;
        $kinds = $group === null ? null : array_keys(array_filter($this->types->all(), static fn (NotificationType $t): bool => $t->group === $group));
        $query = $this->center->query($user, $scope)->latest()->latest('id');
        if ($filter === 'unread') {
            $query->whereNull('read_at');
        }
        if ($kinds !== null) {
            $query->whereIn('kind', $kinds);
        }
        $page = $query->paginate(Preferences::perPage($user instanceof Authenticatable ? $user : null))->withQueryString();
        // Gruppen, in denen es überhaupt etwas gibt — für die Filter
        $groups = [];
        foreach ($this->center->query($user, $scope)->selectRaw('kind, count(*) as total')->groupBy('kind')->get() as $row) {
            $type = $this->types->get((string) $row->getAttribute('kind'));
            if ($type !== null) {
                $groups[$type->group] = ['value' => $type->group, 'label' => Text::t($type->group), 'count' => ($groups[$type->group]['count'] ?? 0) + (int) $row->getAttribute('total')];
            }
        }
        $prefix = $scope === NotificationType::EXTRANET ? 'extranet.notifications.' : 'basalto.notifications.';

        return Inertia::render('core::Notifications', [
            'items' => $this->center->present($page->items(), $scope),
            'pagination' => ['current' => $page->currentPage(), 'last' => $page->lastPage(), 'total' => $page->total(),
                'prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
            'filter' => $filter,
            'group' => $group,
            'groups' => array_values($groups),
            'unread' => $this->center->unread($user, $scope),
            'enabled' => NotificationSettings::of($user)['enabled'],
            'urls' => ['index' => route($prefix.'index'), 'read_all' => route($prefix.'read_all'), 'destroy_read' => route($prefix.'destroy_read'),
                'settings' => route('basalto.profile', ['reiter' => 'benachrichtigungen'])],
        ]);
    }

    /** Für das Aufklappen der Glocke und ihr Nachfragen alle 60 s: Zahl und die neusten acht. */
    public function bell(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $scope = NotificationCenter::scope($request);

        return response()->json([
            'unread' => $this->center->unread($user, $scope),
            'items' => $request->boolean('count') ? [] : $this->center->present($this->center->query($user, $scope)->latest()->latest('id')->limit(8)->get(), $scope),
        ]);
    }

    /** Öffnen: als gelesen markieren, im Backend in die Site der Benachrichtigung wechseln, weiter zum Ziel. */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $user = $this->user($request);
        $row = $this->find($request, $user, $notification);
        $row->markAsRead();
        $data = $row->getAttribute('data');
        $url = is_array($data) && is_string($data['url'] ?? null) ? $data['url'] : null;
        $this->switchSite($request, $user, $row->getAttribute('site_id'));

        return $url === null ? redirect()->route(NotificationCenter::scope($request) === NotificationType::EXTRANET ? 'extranet.notifications.index' : 'basalto.notifications.index')
            : redirect()->away($url);
    }

    /** Aus der Mail (signiert): Site wechseln, die Zeile in der Glocke — falls es sie gibt — als gelesen markieren. */
    public function follow(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $to = (string) $request->query('to', '');
        abort_unless($to !== '' && str_starts_with($to, rtrim((string) config('app.url'), '/').'/'), 404);
        // Die Zeile in der Glocke gibt es nur, wenn die Glocke für diese Art an war — und nur mit gültiger Kennung (PostgreSQL prüft den Typ)
        $id = (string) $request->query('id');
        if (Str::isUuid($id)) {
            $this->center->query($user, NotificationType::BACKEND)->whereKey($id)->whereNull('read_at')->update(['read_at' => now()]);
        }
        $this->switchSite($request, $user, $request->query('site'));

        return redirect()->away($to);
    }

    /** Gelesen ↔ ungelesen. */
    public function read(Request $request, string $notification): RedirectResponse|JsonResponse
    {
        $user = $this->user($request);
        $row = $this->find($request, $user, $notification);
        $row->forceFill(['read_at' => $row->getAttribute('read_at') === null ? now() : null])->save();

        return $request->expectsJson() ? response()->json(['unread' => $this->center->unread($user, NotificationCenter::scope($request))]) : back();
    }

    public function readAll(Request $request): RedirectResponse|JsonResponse
    {
        $user = $this->user($request);
        $scope = NotificationCenter::scope($request);
        $this->center->query($user, $scope)->whereNull('read_at')->update(['read_at' => now()]);

        return $request->expectsJson() ? response()->json(['unread' => 0]) : back()->with('status', __('Alle als gelesen markiert.'));
    }

    public function destroy(Request $request, string $notification): RedirectResponse
    {
        $user = $this->user($request);
        $this->find($request, $user, $notification)->delete();

        return back();
    }

    public function destroyRead(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $count = $this->center->query($user, NotificationCenter::scope($request))->whereNotNull('read_at')->delete();

        return back()->with('status', trans_choice(':count gelesene Benachrichtigung entfernt.|:count gelesene Benachrichtigungen entfernt.', $count, ['count' => $count]));
    }

    /** Einstellungen aus dem Profil (Autosave): Hauptschalter, Kanäle, je Art Glocke und E-Mail. */
    public function settings(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user instanceof Authenticatable, 403);
        $allowed = array_map(static fn (NotificationType $t): string => $t->key, $this->types->forUser($user));
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'bell' => ['required', 'boolean'],
            'mail' => ['required', 'boolean'],
            'types' => ['present', 'array'],
        ]);
        // Die Schlüssel der Arten enthalten Punkte («content.rejected») — `types.*.bell` träfe sie nicht; darum hier je Wert
        $types = [];
        foreach ((array) $data['types'] as $key => $channels) {
            $channels = is_array($channels) ? array_intersect_key($channels, ['bell' => true, 'mail' => true]) : null;
            if ($channels === null || array_filter($channels, static fn (mixed $v): bool => ! in_array($v, [true, false, 0, 1, '0', '1'], true)) !== []) {
                throw ValidationException::withMessages(['types' => __('Jeder Schalter ist ein oder aus.')]);
            }
            $types[(string) $key] = array_map(static fn (mixed $v): bool => in_array($v, [true, 1, '1'], true), $channels);
        }
        NotificationSettings::save($user, ['enabled' => (bool) $data['enabled'], 'bell' => (bool) $data['bell'], 'mail' => (bool) $data['mail'], 'types' => $types], $allowed);

        return back()->with('status', __('Benachrichtigungen gespeichert.'));
    }

    private function find(Request $request, Model $user, string $id): DatabaseNotification
    {
        $row = $this->center->query($user, NotificationCenter::scope($request))->whereKey($id)->first();
        abort_if($row === null, 404);

        return $row;
    }

    private function switchSite(Request $request, Model $user, mixed $siteId): void
    {
        if (NotificationCenter::scope($request) !== NotificationType::BACKEND || ! is_numeric($siteId) || ! $user instanceof Authenticatable) {
            return;
        }
        $site = Site::query()->find((int) $siteId);
        if ($site !== null && $site->roleKeyOf($user) !== null) {
            $request->session()->put(SelectBackendSite::SESSION_KEY, $site->id);
        }
    }

    private function user(Request $request): Model
    {
        $user = $request->user();
        abort_unless($user instanceof Model, 403);

        return $user;
    }
}
