<?php

declare(strict_types=1);

namespace Basalto\Core\Notifications;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Live\Live;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use RuntimeException;

/**
 * Der Kanal «Glocke» (TB 27): schreibt in die Tabelle `notifications` mit Art, Schicht und Site. Trägt die Nachricht
 * einen Bündel-Schlüssel (`collapse`) und liegt dazu noch eine ungelesene vor, zählt diese hoch statt eine neue
 * anzulegen — zehn Einsendungen in einer Stunde sind eine Zeile «10 neue Einsendungen», nicht zehn.
 */
final class BellChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toBell') || ! method_exists($notifiable, 'notifications')) {
            throw new RuntimeException('Die Glocke braucht toBell() an der Benachrichtigung und Notifiable am Empfänger');
        }
        /** @var array{kind: string, scope: string, site_id: int|null, title: string, params: array<string, string|int>, collapse?: string|null} $data */
        $data = $notification->toBell($notifiable);
        /** @var MorphMany<DatabaseNotification, Model> $relation */
        $relation = $notifiable->notifications();
        $collapse = $data['collapse'] ?? null;
        if (is_string($collapse) && $collapse !== '') {
            $open = (clone $relation)->whereNull('read_at')->where('kind', $data['kind'])->where('scope', $data['scope'])
                ->where('site_id', $data['site_id'])->latest()->get()
                ->first(static fn (DatabaseNotification $row): bool => ($row->data['collapse'] ?? null) === $collapse);
            if ($open !== null) {
                $count = (int) ($open->data['count'] ?? 1) + 1;
                $open->forceFill(['data' => [...$data, 'count' => $count], 'created_at' => now()])->save();
                self::signal($notifiable, $data['site_id']);

                return;
            }
        }
        $relation->create([
            'id' => $notification->id,
            'type' => $notification::class,
            'kind' => $data['kind'],
            'scope' => $data['scope'],
            'site_id' => $data['site_id'],
            'data' => [...$data, 'count' => 1],
            'read_at' => null,
        ]);
        self::signal($notifiable, $data['site_id']);
    }

    /**
     * CR-008: Zähler der Glocke live — Kundenkonten über `konto.{id}` (je Site), Benutzer des Backends und des Extranets
     * über `benutzer.{id}` (ohne Site).
     */
    private static function signal(object $notifiable, ?int $siteId): void
    {
        if (! $notifiable instanceof Model) {
            return;
        }
        $id = (int) $notifiable->getKey();
        $notifiable instanceof Customer ? app(Live::class)->signal($siteId, 'konto.'.$id) : app(Live::class)->signal(0, 'benutzer.'.$id);
    }
}
