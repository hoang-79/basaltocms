<?php

declare(strict_types=1);

namespace Basalto\Core\Saas;

use Basalto\Core\Models\SiteDomain;

/**
 * Zähler für das Domain-Limit der Cloud-Organisation (TB 12 Scheibe 3, Feasibility «SaaS-Domains» § 3.1: Growth 50,
 * Business 250 Kundendomains, darüber 0.25 USD je Domain und Monat). Gezählt werden die Cloud-Einträge dieser Instanz
 * (Zeilen mit `cloud_domain_id` — `www` und Apex sind ein Eintrag). Beide Instanzen teilen das Limit der Organisation:
 * `basalto.cloud.domain_limit` je Instanz auf ihren Anteil setzen. Ab 80 % warnt die Seite «Betrieb».
 *
 * @phpstan-type Status array{used: int, limit: int, percent: int, warning: bool}
 */
final class CloudDomainLimit
{
    public const WARNING_PERCENT = 80;

    /** @return Status */
    public static function status(): array
    {
        $used = SiteDomain::query()->whereNotNull('cloud_domain_id')->count();
        $limit = max(1, (int) config('basalto.cloud.domain_limit', 50));
        $percent = (int) floor($used * 100 / $limit);

        return ['used' => $used, 'limit' => $limit, 'percent' => $percent, 'warning' => $percent >= self::WARNING_PERCENT];
    }
}
