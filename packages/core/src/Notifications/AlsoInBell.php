<?php

declare(strict_types=1);

namespace Basalto\Core\Notifications;

use Illuminate\Database\Eloquent\Model;

/**
 * Für bestehende Pflicht-Mails (TB 27): die Mail bleibt, wie sie ist, und dieselbe Nachricht steht zusätzlich in der
 * Glocke — sofern die Person die Glocke für diese Art nicht abgeschaltet hat. Die Klasse liefert die Art
 * (`bellKind()`) und Titel, Text und Adresse (`bell()`).
 */
trait AlsoInBell
{
    abstract protected function bellKind(): string;

    /** @return array{title: string, params?: array<string, string|int>, body?: string|null, body_params?: array<string, string|int>, url?: string|null} */
    abstract protected function bell(): array;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $type = app(NotificationTypes::class)->get($this->bellKind());
        $bell = $type !== null && NotificationSettings::channels($notifiable instanceof Model ? $notifiable : null, $type)['bell'];

        return $bell ? ['mail', BellChannel::class] : ['mail'];
    }

    /** @return array<string, mixed> */
    public function toBell(object $notifiable): array
    {
        $bell = $this->bell();

        return ['kind' => $this->bellKind(), 'scope' => NotificationType::BACKEND, 'site_id' => null, 'title' => $bell['title'], 'title_many' => null,
            'params' => $bell['params'] ?? [], 'body' => $bell['body'] ?? null, 'body_params' => $bell['body_params'] ?? [], 'url' => $bell['url'] ?? null,
            'action' => null, 'icon' => app(NotificationTypes::class)->get($this->bellKind())->icon ?? 'license', 'collapse' => null];
    }
}
