<?php

declare(strict_types=1);

namespace Basalto\Core\Notifications;

use Basalto\Core\Backend\Dates;
use Basalto\Core\Extranet\Extranet;
use Basalto\Core\Live\Live;
use Basalto\Core\Live\LivePush;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Support\Text;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Glocke und Center lesen hier (TB 27): die Benachrichtigungen einer Person in der Schicht der Anfrage (Backend oder
 * Extranet) — im Backend aus allen Sites, deren Mitglied sie noch ist, und die ohne Site. Texte übersetzt in die
 * Sprache der Oberfläche, Zeiten nach dem Profil.
 *
 * @phpstan-type Item array{id: string, kind: string, title: string, body: string|null, icon: string, group: string|null, read: bool, at: string|null, day: string, site: string|null, open_url: string, read_url: string, destroy_url: string}
 */
final class NotificationCenter
{
    public function __construct(private readonly NotificationTypes $types, private readonly Dates $dates, private readonly CurrentSite $current) {}

    public static function scope(?Request $request = null): string
    {
        return Extranet::isRequest($request) ? NotificationType::EXTRANET : NotificationType::BACKEND;
    }

    /** @return Builder<DatabaseNotification> */
    public function query(Model $user, string $scope): Builder
    {
        /** @var Builder<DatabaseNotification> $query */
        $query = DatabaseNotification::query()->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->getKey())->where('scope', $scope);
        if ($scope === NotificationType::BACKEND && $user instanceof Authenticatable) {
            $siteIds = Site::query()->forUser($user)->pluck('id')->all();
            $query->where(static fn (Builder $q) => $q->whereNull('site_id')->orWhereIn('site_id', $siteIds));
        }

        return $query;
    }

    public function unread(?Model $user, string $scope): int
    {
        return $user === null ? 0 : $this->query($user, $scope)->whereNull('read_at')->count();
    }

    /**
     * Für Kopfzeile (geteilte Props): Zahl der ungelesenen und die Adressen.
     *
     * CR-008 (LV-09): dazu der Schlüssel `benutzer.{id}` für die Live-Aktualisierung und — wenn eingerichtet — die
     * Verbindungsdaten für Push.
     *
     * @return array{unread: int, bell_url: string, index_url: string, read_all_url: string, settings_url: string, live: array{site: int, key: string, token: string, version: int, interval: int, push: array{key: string, host: string, port: int, scheme: string}|null}}|null
     */
    public function shared(?Model $user, string $scope): ?array
    {
        if ($user === null) {
            return null;
        }
        $prefix = $scope === NotificationType::EXTRANET ? 'extranet.notifications.' : 'basalto.notifications.';
        $live = app(Live::class);
        $key = 'benutzer.'.$user->getKey();

        return ['unread' => $this->unread($user, $scope), 'bell_url' => route($prefix.'bell'), 'index_url' => route($prefix.'index'), 'read_all_url' => route($prefix.'read_all'),
            'settings_url' => route('basalto.profile', ['reiter' => 'benachrichtigungen']),
            'live' => ['site' => 0, 'key' => $key, 'token' => $live->token(0, $key), 'version' => $live->version(0, $key), 'interval' => $live->interval(), 'push' => app(LivePush::class)->clientConfig()]];
    }

    /**
     * @param  iterable<DatabaseNotification>  $rows
     * @return list<Item>
     */
    public function present(iterable $rows, string $scope): array
    {
        $relative = $this->dates->relativeFormatter();
        $timezone = $this->dates->timezone();
        $today = CarbonImmutable::now($timezone)->format('Y-m-d');
        $yesterday = CarbonImmutable::now($timezone)->subDay()->format('Y-m-d');
        $currentSite = $this->current->id();
        $siteNames = [];
        $prefix = $scope === NotificationType::EXTRANET ? 'extranet.notifications.' : 'basalto.notifications.';
        $items = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $data */
            $data = is_array($row->getAttribute('data')) ? $row->getAttribute('data') : [];
            $type = $this->types->get($row->getAttribute('kind'));
            $count = (int) ($data['count'] ?? 1);
            $params = is_array($data['params'] ?? null) ? $data['params'] : [];
            $title = $count > 1 && is_string($data['title_many'] ?? null) ? Text::t($data['title_many'], [...$params, 'count' => $count]) : Text::t((string) ($data['title'] ?? ''), $params);
            $body = is_string($data['body'] ?? null) && $data['body'] !== '' ? Text::t($data['body'], is_array($data['body_params'] ?? null) ? $data['body_params'] : []) : null;
            $createdAt = $row->getAttribute('created_at');
            $created = $createdAt instanceof \DateTimeInterface ? CarbonImmutable::instance($createdAt)->setTimezone($timezone) : null;
            $day = $created?->format('Y-m-d') ?? '';
            $siteId = $row->getAttribute('site_id');
            $site = null;
            if ($scope === NotificationType::BACKEND && $siteId !== null && (int) $siteId !== $currentSite) {
                $site = $siteNames[$siteId] ??= (string) Site::query()->whereKey($siteId)->value('name');
            }
            $items[] = [
                'id' => (string) $row->getKey(),
                'kind' => (string) $row->getAttribute('kind'),
                'title' => $title,
                'body' => $body,
                'icon' => is_string($data['icon'] ?? null) ? $data['icon'] : ($type->icon ?? 'module'),
                'group' => $type === null ? null : Text::t($type->group),
                'read' => $row->getAttribute('read_at') !== null,
                'at' => $relative($createdAt),
                'day' => match ($day) {
                    $today => __('Heute'),
                    $yesterday => __('Gestern'),
                    default => (string) $this->dates->date($createdAt),
                },
                'site' => $site,
                'open_url' => route($prefix.'open', ['notification' => $row->getKey()]),
                'read_url' => route($prefix.'read', ['notification' => $row->getKey()]),
                'destroy_url' => route($prefix.'destroy', ['notification' => $row->getKey()]),
            ];
        }

        return $items;
    }
}
