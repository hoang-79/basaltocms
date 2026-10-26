<?php

declare(strict_types=1);

namespace Basalto\Core\Privacy;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use Closure;

/**
 * Auskunft und Löschen nur für eine Site (CR-011 TB 61): Im Kundenkonto gehören die Daten der Person auf dieser Site —
 * auf einer SaaS-Instanz liegen auf anderen Sites Daten anderer Verantwortlicher. Innerhalb von {@see within()} finden
 * die Quellen (`basalto.person-data`) die Kundenkonten nur auf der Site ({@see Customer::byEmail()}).
 * Ohne Rahmen (Befehl `basalto:privacy`) gilt die ganze Installation.
 */
final class PersonScope
{
    private ?int $siteId = null;

    public function siteId(): ?int
    {
        return $this->siteId;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function within(Site $site, Closure $callback): mixed
    {
        $previous = $this->siteId;
        $this->siteId = $site->id;
        try {
            return $callback();
        } finally {
            $this->siteId = $previous;
        }
    }
}
