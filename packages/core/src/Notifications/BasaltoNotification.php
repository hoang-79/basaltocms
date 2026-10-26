<?php

declare(strict_types=1);

namespace Basalto\Core\Notifications;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Eine Benachrichtigung aus dem Verzeichnis (TB 27), gebaut mit `Notify`. Sie trägt nur fertige Daten (Texte als
 * deutsche Schlüssel mit Platzhaltern, Adresse, Site) — die Warteschlange kennt keinen Mandanten. Die Glocke übersetzt
 * beim Anzeigen in die Sprache der Person, die Mail beim Versand (Laravel nimmt dafür die Sprache des Empfängers).
 * Die Glocke schreibt sofort, die Mail geht über die Warteschlange.
 *
 * Module, die eine eigene Mail brauchen (Anhang, eigener Aufbau), erben und überschreiben `toMail()`.
 *
 * @phpstan-type Message array{kind: string, scope: string, site_id: int|null, title: string, title_many: string|null, params: array<string, string|int>, body: string|null, body_params: array<string, string|int>, url: string|null, action: string|null, icon: string, collapse: string|null}
 */
class BasaltoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param Message $message */
    public function __construct(public readonly array $message) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $type = app(NotificationTypes::class)->get($this->message['kind']);
        if ($type === null) {
            return [];
        }
        $channels = NotificationSettings::channels($notifiable instanceof Model ? $notifiable : null, $type);
        // CR-006 (TB 38): Kundenkonten mit «einmal täglich» bekommen die Mail in der Zusammenfassung
        if ($notifiable instanceof Customer && $channels['mail'] && ! $type->mailRequired && (bool) data_get($notifiable->preferences, 'notifications.digest', false)) {
            $channels['mail'] = false;
        }

        return array_values(array_filter([$channels['bell'] ? BellChannel::class : null, $channels['mail'] ? 'mail' : null]));
    }

    /** @return array<string, string> Die Glocke ohne Warteschlange — sie soll beim nächsten Blick schon klingeln */
    public function viaConnections(): array
    {
        return [BellChannel::class => 'sync'];
    }

    /** @return Message */
    public function toBell(object $notifiable): array
    {
        return $this->message;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $m = $this->message;
        $mail = (new MailMessage)->subject(__($m['title'], $m['params']))->greeting(__('Guten Tag'));
        $mail->line(__($m['title'], $m['params']));
        if ($m['body'] !== null && $m['body'] !== '') {
            $mail->line(__($m['body'], $m['body_params']));
        }
        if ($m['url'] !== null) {
            $mail->action(__($m['action'] ?? 'Ansehen'), $this->mailUrl());
        }
        $type = app(NotificationTypes::class)->get($m['kind']);
        if ($type !== null && ! $type->mailRequired) {
            $mail->line(__('Diese E-Mail lässt sich im Profil unter «Benachrichtigungen» abschalten.'));
        }

        return $mail;
    }

    /**
     * Adresse für die Mail: im Backend mit Site über einen signierten Umweg, der die Site wechselt und die Zeile in
     * der Glocke als gelesen markiert — sonst landet ein Link aus Site B in der gerade gewählten Site A.
     */
    protected function mailUrl(): string
    {
        $m = $this->message;
        // Relative Adressen (aus einer Anfrage im Extranet für eine Site des Kunden) gehören ins CMS der Anwendung
        $url = str_starts_with((string) $m['url'], '/') ? rtrim((string) config('app.url'), '/').$m['url'] : (string) $m['url'];
        // CR-006 (TB 38): Nachrichten an Kundenkonten zeigen auf den Host ihrer Site, nicht auf die Anwendung
        if ($m['scope'] === NotificationType::FRONTEND && $m['site_id'] !== null && str_starts_with((string) $m['url'], '/')) {
            $host = Site::query()->find($m['site_id'])?->domains()->orderByDesc('is_primary')->value('host');

            return is_string($host) && $host !== '' ? 'https://'.$host.$m['url'] : $url;
        }
        if ($m['scope'] !== NotificationType::BACKEND || $m['site_id'] === null || $m['url'] === null) {
            return $url;
        }

        return URL::signedRoute('basalto.notifications.follow', ['id' => $this->id, 'site' => $m['site_id'], 'to' => $url]);
    }
}
