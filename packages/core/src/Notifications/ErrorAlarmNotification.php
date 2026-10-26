<?php

declare(strict_types=1);

namespace Basalto\Core\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Fehleralarm an die Betreiber der Installation (B-09): Zahl, Fenster, Schwelle, die häufigsten Ausnahmen aus Pulse und die Wege zum Nachsehen. */
final class ErrorAlarmNotification extends Notification
{
    /** @param  list<array{class: string, count: int}>  $top */
    public function __construct(
        public readonly int $count,
        public readonly int $minutes,
        public readonly int $threshold,
        public readonly string $installation,
        public readonly string $operationsUrl,
        public readonly ?string $pulseUrl,
        public readonly array $top = [],
        public readonly int $pauseMinutes = 60,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->error()
            ->subject(__('Basalto: :count Fehler in :minutes Minuten — :installation', ['count' => $this->count, 'minutes' => $this->minutes, 'installation' => $this->installation]))
            ->greeting(__('Fehler häufen sich'))
            ->line(__(':installation hat in den letzten :minutes Minuten :count Ausnahmen gemeldet (Schwelle :threshold).', ['installation' => $this->installation, 'minutes' => $this->minutes, 'count' => $this->count, 'threshold' => $this->threshold]));
        foreach ($this->top as $exception) {
            $mail->line(sprintf('**%s** — %d×', $exception['class'], $exception['count']));
        }
        $mail->action(__('Betrieb ansehen'), $this->operationsUrl);
        if ($this->pulseUrl !== null) {
            $mail->line(__('Einzelheiten mit Ort und Zeit im Pulse-Dashboard: :url', ['url' => $this->pulseUrl]));
        }

        return $mail->line(__('Der nächste Alarm kommt frühestens in :minutes Minuten. Schwelle, Fenster und Pause stehen unter Einstellungen › Betrieb.', ['minutes' => $this->pauseMinutes]))
            ->salutation('Basalto');
    }
}
