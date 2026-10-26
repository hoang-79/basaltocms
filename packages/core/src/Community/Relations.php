<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\FrontendAccounts\Customer;
use Illuminate\Support\Facades\DB;

/**
 * Folgen und Blockieren (CR-006 FO-K10, FO-18). Folgen ist öffentlich (Liste im Profil) und bringt Neues der
 * Gefolgten in die Glocke; Blockieren ist privat: Beiträge der blockierten Person sind zugeklappt, sie kann keine
 * Konversation mit einem beginnen, ihre Erwähnungen, Zitate und Reaktionen lösen keine Nachricht aus.
 *
 * Kein Zustand je Anfrage — jede Frage geht an die Datenbank (Octane).
 */
final class Relations
{
    public function follow(Customer $customer, Customer $other): void
    {
        if ($customer->id === $other->id || $customer->site_id !== $other->site_id) {
            return;
        }
        DB::table('community_follows')->insertOrIgnore(['site_id' => $customer->site_id, 'customer_id' => $customer->id, 'followed_id' => $other->id, 'created_at' => now()]);
    }

    public function unfollow(Customer $customer, Customer $other): void
    {
        DB::table('community_follows')->where('customer_id', $customer->id)->where('followed_id', $other->id)->delete();
    }

    public function follows(?Customer $customer, int $otherId): bool
    {
        return $customer !== null && DB::table('community_follows')->where('customer_id', $customer->id)->where('followed_id', $otherId)->exists();
    }

    /** @return list<int> wem die Person folgt */
    public function following(int $customerId): array
    {
        return array_values(array_map('intval', DB::table('community_follows')->where('customer_id', $customerId)->orderByDesc('id')->pluck('followed_id')->all()));
    }

    /** @return list<int> wer der Person folgt */
    public function followers(int $customerId): array
    {
        return array_values(array_map('intval', DB::table('community_follows')->where('followed_id', $customerId)->orderByDesc('id')->pluck('customer_id')->all()));
    }

    /** Blockieren hebt das Folgen in beide Richtungen auf. */
    public function block(Customer $customer, Customer $other): void
    {
        if ($customer->id === $other->id || $customer->site_id !== $other->site_id) {
            return;
        }
        DB::table('community_blocks')->insertOrIgnore(['site_id' => $customer->site_id, 'customer_id' => $customer->id, 'blocked_id' => $other->id, 'created_at' => now()]);
        $this->unfollow($customer, $other);
        $this->unfollow($other, $customer);
    }

    public function unblock(Customer $customer, Customer $other): void
    {
        DB::table('community_blocks')->where('customer_id', $customer->id)->where('blocked_id', $other->id)->delete();
    }

    /** Hat `$customerId` die Person `$otherId` blockiert? */
    public function blocks(int $customerId, ?int $otherId): bool
    {
        return $otherId !== null && DB::table('community_blocks')->where('customer_id', $customerId)->where('blocked_id', $otherId)->exists();
    }

    /** @return list<int> wen die Person blockiert */
    public function blocked(?Customer $customer): array
    {
        return $customer === null ? [] : array_values(array_map('intval', DB::table('community_blocks')->where('customer_id', $customer->id)->pluck('blocked_id')->all()));
    }
}
