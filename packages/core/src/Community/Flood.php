<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use Illuminate\Support\Facades\Cache;

/**
 * Flood-Control (CR-006 FO-17): ein Mindestabstand zwischen zwei Beiträgen je Konto (Einstellung in Sekunden, 0 = aus).
 * Wer das Recht «Ohne Wartezeit schreiben» hat (ab Werk Moderation und Team), wartet nie.
 */
final class Flood
{
    public const PERMISSION = 'community.no_flood';

    public function __construct(private readonly ModerationSettings $settings, private readonly VisitorAccess $access) {}

    /** Wie viele Sekunden die Person noch warten muss (0 = darf). */
    public function wait(Site $site, Customer $customer): int
    {
        $seconds = $this->settings->int($site, 'flood_seconds');
        if ($seconds === 0 || $this->access->allows($site, $customer, self::PERMISSION)) {
            return 0;
        }
        $last = Cache::get($this->key($site, $customer));

        return is_int($last) ? max(0, $last + $seconds - now()->getTimestamp()) : 0;
    }

    public function hit(Site $site, Customer $customer): void
    {
        $seconds = $this->settings->int($site, 'flood_seconds');
        if ($seconds > 0) {
            Cache::put($this->key($site, $customer), now()->getTimestamp(), $seconds);
        }
    }

    public function error(Site $site, Customer $customer, string $lang): ?string
    {
        $wait = $this->wait($site, $customer);

        return $wait === 0 ? null : trans_choice('Bitte warten Sie noch :count Sekunde bis zum nächsten Beitrag.|Bitte warten Sie noch :count Sekunden bis zum nächsten Beitrag.', $wait, ['count' => $wait], $lang);
    }

    private function key(Site $site, Customer $customer): string
    {
        return 'community-flood:'.$site->id.':'.$customer->id;
    }
}
