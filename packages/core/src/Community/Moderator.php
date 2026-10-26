<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\FrontendAccounts\Customer;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Wer moderiert (CR-006 FO-15): das Team im Extranet (Benutzer) oder ein moderierendes Kundenkonto an Ort und Stelle.
 * Beide Wege rufen dieselben Aktionen; das Protokoll hält fest, wer es war.
 */
final readonly class Moderator
{
    private function __construct(public ?Authenticatable $user, public ?Customer $customer) {}

    public static function staff(Authenticatable $user): self
    {
        return new self($user, null);
    }

    public static function member(Customer $customer): self
    {
        return new self(null, $customer);
    }

    /** Automatisch (etwa vorläufig ausgeblendet nach Meldungen) — weder Team noch Mitglied. */
    public static function system(): self
    {
        return new self(null, null);
    }

    public function userId(): ?int
    {
        $id = $this->user?->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }

    public function customerId(): ?int
    {
        return $this->customer?->id;
    }
}
