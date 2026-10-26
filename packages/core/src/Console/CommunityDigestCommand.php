<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Community\DigestNotification;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use Basalto\Core\Notifications\NotificationType;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Die tägliche Zusammenfassung der Community verschicken (CR-006 FO-11): je Konto mit «einmal täglich» eine Mail mit
 * den ungelesenen Benachrichtigungen, die noch in keiner Zusammenfassung standen.
 */
final class CommunityDigestCommand extends Command
{
    protected $signature = 'basalto:community-digest';

    protected $description = 'Verschickt die tägliche Zusammenfassung der Community an Konten, die sie gewählt haben';

    public function handle(): int
    {
        $sent = 0;
        $rows = DatabaseNotification::query()->where('notifiable_type', (new Customer)->getMorphClass())->where('scope', NotificationType::FRONTEND)
            ->whereNull('read_at')->where('created_at', '>=', now()->subDays(7))->orderBy('created_at')->get()
            ->filter(static fn (DatabaseNotification $row): bool => ! (bool) data_get($row->getAttribute('data'), 'digested', false))
            ->groupBy('notifiable_id');
        foreach ($rows as $customerId => $items) {
            $customer = Customer::query()->withoutGlobalScopes()->find($customerId);
            if ($customer === null || ! (bool) data_get($customer->preferences, 'notifications.digest', false) || ! (bool) data_get($customer->preferences, 'notifications.mail', true)) {
                continue;
            }
            $site = Site::query()->find($customer->site_id);
            if ($site === null) {
                continue;
            }
            $customer->notify(new DigestNotification($site, $items->values()));
            foreach ($items as $row) {
                $row->forceFill(['data' => [...(array) $row->getAttribute('data'), 'digested' => true]])->save();
            }
            $sent++;
        }
        $this->line((string) __(':count Zusammenfassung(en) verschickt.', ['count' => $sent]));

        return self::SUCCESS;
    }
}
