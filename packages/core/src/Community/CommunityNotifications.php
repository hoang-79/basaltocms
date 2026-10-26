<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Notifications\NotificationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * Die Glocke der Kundenkonten (CR-006 FO-11, FO-K7): Benachrichtigungen der Schicht «frontend» je Site — zählen,
 * auflisten, als gelesen markieren. Texte sind Schlüssel mit Platzhaltern; übersetzt wird beim Anzeigen.
 */
final class CommunityNotifications
{
    /** @return Builder<DatabaseNotification> */
    private function query(Customer $customer): Builder
    {
        return DatabaseNotification::query()->where('notifiable_type', $customer->getMorphClass())->where('notifiable_id', $customer->id)
            ->where('scope', NotificationType::FRONTEND)->where('site_id', $customer->site_id);
    }

    public function unread(?Customer $customer): int
    {
        return $customer === null ? 0 : $this->query($customer)->whereNull('read_at')->count();
    }

    /** @return Collection<int, DatabaseNotification> */
    public function latest(Customer $customer, int $limit = 50): Collection
    {
        return $this->query($customer)->latest()->limit($limit)->get();
    }

    public function find(Customer $customer, string $id): ?DatabaseNotification
    {
        return $this->query($customer)->whereKey($id)->first();
    }

    public function markAllRead(Customer $customer): void
    {
        $this->query($customer)->whereNull('read_at')->update(['read_at' => now()]);
    }

    /** TB 40: Wer eine Konversation (oder ein anderes Ziel) öffnet, hat ihre Nachrichten gesehen. */
    public function markReadFor(Customer $customer, string $url): void
    {
        foreach ($this->query($customer)->whereNull('read_at')->limit(200)->get() as $row) {
            if (self::url($row) === $url) {
                $row->markAsRead();
            }
        }
    }

    /** Der Titel in der Sprache — mit Zahl, wenn mehrere zusammengefasst sind. */
    public static function title(DatabaseNotification $row, string $lang): string
    {
        $data = is_array($row->getAttribute('data')) ? $row->getAttribute('data') : [];
        $count = (int) ($data['count'] ?? 1);
        $params = is_array($data['params'] ?? null) ? $data['params'] : [];
        $many = is_string($data['title_many'] ?? null) ? $data['title_many'] : null;

        return $count > 1 && $many !== null ? (string) __($many, [...$params, 'count' => $count], $lang) : (string) __((string) ($data['title'] ?? ''), $params, $lang);
    }

    public static function body(DatabaseNotification $row, string $lang): string
    {
        $data = is_array($row->getAttribute('data')) ? $row->getAttribute('data') : [];

        return is_string($data['body'] ?? null) && $data['body'] !== '' ? (string) __($data['body'], is_array($data['body_params'] ?? null) ? $data['body_params'] : [], $lang) : '';
    }

    public static function url(DatabaseNotification $row): ?string
    {
        $data = $row->getAttribute('data');
        $url = is_array($data) ? ($data['url'] ?? null) : null;

        return is_string($url) && str_starts_with($url, '/') ? $url : null;
    }
}
