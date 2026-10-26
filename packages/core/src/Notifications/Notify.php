<?php

declare(strict_types=1);

namespace Basalto\Core\Notifications;

use Basalto\Core\Access\Level;
use Basalto\Core\Models\Site;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Benachrichtigen in einer Zeile (TB 27) — für Kern und Plugins:
 *
 *     Notify::make('shop.order')
 *         ->title('Neue Bestellung :number', ['number' => $order->number])
 *         ->body('Von :name über :amount.', ['name' => $order->name, 'amount' => $amount])
 *         ->url(route('basalto.shop.orders.show', $order), 'Bestellung öffnen')
 *         ->toArea($site)          // alle mit dem Bereich der Art auf der Site
 *         ->except($request->user()) // wer es selbst ausgelöst hat, braucht keine Nachricht
 *         ->send();
 *
 * Texte sind deutsche Schlüssel der Übersetzung mit Platzhaltern — übersetzt wird beim Anzeigen bzw. Versenden in die
 * Sprache der Person. Welche Kanäle (Glocke, E-Mail) greifen, entscheiden die Einstellungen der Person.
 */
final class Notify
{
    /** @var Collection<int, Model> */
    private Collection $recipients;

    private ?int $except = null;

    private string $title = '';

    private ?string $titleMany = null;

    /** @var array<string, string|int> */
    private array $params = [];

    private ?string $body = null;

    /** @var array<string, string|int> */
    private array $bodyParams = [];

    private ?string $url = null;

    private ?string $action = null;

    private ?int $siteId = null;

    private ?string $collapse = null;

    private function __construct(private readonly NotificationType $type)
    {
        $this->recipients = collect();
    }

    /** Eine angemeldete Art — sonst eine Ausnahme (Tippfehler fallen so im Test auf, nicht beim Kunden). */
    public static function make(string $kind): self
    {
        return new self(app(NotificationTypes::class)->require($kind));
    }

    /** @param array<string, string|int> $params */
    public function title(string $text, array $params = []): self
    {
        $this->title = $text;
        $this->params = $params;

        return $this;
    }

    /**
     * Mit `collapse()`: der Titel, sobald mehrere zusammengefasst sind — `:count` steht für die Zahl.
     */
    public function titleMany(string $text): self
    {
        $this->titleMany = $text;

        return $this;
    }

    /** @param array<string, string|int> $params */
    public function body(string $text, array $params = []): self
    {
        $this->body = $text;
        $this->bodyParams = $params;

        return $this;
    }

    /**
     * Wohin ein Klick führt; `$action` beschriftet den Knopf in der Mail. Relativ (`/admin/…`) für Seiten des CMS, wenn
     * die Anfrage auf einem anderen Host läuft (Extranet) — die Mail setzt dann die Adresse der Anwendung davor.
     */
    public function url(?string $url, ?string $action = null): self
    {
        $this->url = $url;
        $this->action = $action;

        return $this;
    }

    public function site(Site|int|null $site): self
    {
        $this->siteId = $site instanceof Site ? (int) $site->getKey() : $site;

        return $this;
    }

    /** Ungelesene mit demselben Schlüssel zusammenfassen (z. B. je Formular) statt eine neue Zeile anzulegen. */
    public function collapse(string $key): self
    {
        $this->collapse = $key;

        return $this;
    }

    /** @param Model|iterable<Model>|null $users */
    public function to(Model|iterable|null $users): self
    {
        if ($users === null) {
            return $this;
        }
        $this->recipients = $this->recipients->merge($users instanceof Model ? [$users] : collect($users));

        return $this;
    }

    /** Alle der Site mit dem Bereich der Art (oder einem anderen) in mindestens der Stufe; setzt auch die Site. */
    public function toArea(Site $site, ?string $area = null, ?Level $level = null): self
    {
        $this->siteId ??= (int) $site->getKey();

        return $this->to(Recipients::site($site, $area ?? $this->type->area, $level ?? $this->type->level));
    }

    /** Das Team des Herstellers mit dem Bereich der Art (Extranet). */
    public function toExtranet(?string $area = null, ?Level $level = null): self
    {
        return $this->to(Recipients::extranet($area ?? $this->type->area, $level ?? $this->type->level));
    }

    public function except(Authenticatable|int|null $user): self
    {
        $this->except = $user instanceof Authenticatable ? (int) $user->getAuthIdentifier() : $user;

        return $this;
    }

    /** Verschicken — gibt die Zahl der Empfänger zurück. */
    public function send(): int
    {
        $recipients = $this->recipients->unique(static fn (Model $user): mixed => $user::class.':'.$user->getKey())
            ->reject(fn (Model $user): bool => $this->except !== null && (int) $user->getKey() === $this->except)
            ->values();
        if ($recipients->isEmpty()) {
            return 0;
        }
        Notification::send($recipients, new BasaltoNotification([
            'kind' => $this->type->key,
            'scope' => $this->type->scope,
            'site_id' => $this->type->scope === NotificationType::EXTRANET ? null : $this->siteId,
            'title' => $this->title !== '' ? $this->title : $this->type->label,
            'title_many' => $this->titleMany,
            'params' => $this->params,
            'body' => $this->body,
            'body_params' => $this->bodyParams,
            'url' => $this->url,
            'action' => $this->action,
            'icon' => $this->type->icon,
            'collapse' => $this->collapse,
        ]));

        return $recipients->count();
    }
}
