<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\Models\Site;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Die tägliche Zusammenfassung der Community (CR-006 FO-11): eine Mail mit allem Ungelesenen seit der letzten — für
 * Konten, die «einmal täglich» gewählt haben.
 */
final class DigestNotification extends Notification
{
    /** @param  Collection<int, DatabaseNotification>  $items */
    public function __construct(private readonly Site $site, private readonly Collection $items) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lang = method_exists($notifiable, 'preferredLocale') ? (string) $notifiable->preferredLocale() : 'de';
        $host = $this->site->domains()->orderByDesc('is_primary')->value('host');
        $base = is_string($host) && $host !== '' ? 'https://'.$host : rtrim((string) config('app.url'), '/');
        $mail = (new MailMessage)->subject((string) trans_choice(':count Neuigkeit in der Community von :site|:count Neuigkeiten in der Community von :site', $this->items->count(),
            ['count' => $this->items->count(), 'site' => $this->site->name], $lang))->greeting((string) __('Guten Tag', [], $lang));
        foreach ($this->items->take(20) as $row) {
            $url = CommunityNotifications::url($row);
            $mail->line('• '.CommunityNotifications::title($row, $lang).($url !== null ? ' — '.$base.$url : ''));
        }
        if ($this->items->count() > 20) {
            $mail->line((string) __('… und :count weitere.', ['count' => $this->items->count() - 20], $lang));
        }

        return $mail->line((string) __('Diese Zusammenfassung lässt sich in Ihrem Profil in der Community ändern oder abschalten.', [], $lang));
    }
}
