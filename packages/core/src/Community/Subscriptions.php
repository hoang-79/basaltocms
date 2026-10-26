<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\FrontendAccounts\Customer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Abonnieren und Ignorieren (CR-006 FO-10): Ein Kundenkonto beobachtet einen Inhalt (`watch` — Benachrichtigung bei
 * Neuem) oder blendet ihn aus (`ignore`). Je Konto und Inhalt höchstens ein Eintrag.
 */
final class Subscriptions
{
    public const WATCH = 'watch';

    public const IGNORE = 'ignore';

    public function set(Customer $customer, string $type, int $id, ?string $mode): void
    {
        $where = ['customer_id' => $customer->id, 'subject_type' => $type, 'subject_id' => $id];
        if ($mode === null) {
            DB::table('community_subscriptions')->where($where)->delete();

            return;
        }
        DB::table('community_subscriptions')->updateOrInsert($where, ['site_id' => $customer->site_id, 'mode' => $mode, 'created_at' => now()]);
    }

    /** Beobachten, wenn noch nichts eingestellt ist — für «automatisch abonnieren» (ein Ignorieren bleibt). */
    public function watchUnlessSet(Customer $customer, string $type, int $id): void
    {
        if ($this->modeOf($customer, $type, $id) === null) {
            $this->set($customer, $type, $id, self::WATCH);
        }
    }

    public function modeOf(?Customer $customer, string $type, int $id): ?string
    {
        if ($customer === null) {
            return null;
        }
        $mode = DB::table('community_subscriptions')->where(['customer_id' => $customer->id, 'subject_type' => $type, 'subject_id' => $id])->value('mode');

        return is_string($mode) ? $mode : null;
    }

    /** @return Collection<int, Customer> wer den Inhalt beobachtet */
    public function watchers(string $type, int $id): Collection
    {
        $ids = DB::table('community_subscriptions')->where(['subject_type' => $type, 'subject_id' => $id, 'mode' => self::WATCH])->pluck('customer_id');

        return Customer::query()->withoutGlobalScopes()->whereIn('id', $ids)->get();
    }

    /** @return list<int> Inhalte einer Art, die das Konto ausblendet */
    public function ignored(?Customer $customer, string $type): array
    {
        if ($customer === null) {
            return [];
        }

        return array_values(array_map('intval', DB::table('community_subscriptions')->where(['customer_id' => $customer->id, 'subject_type' => $type, 'mode' => self::IGNORE])
            ->pluck('subject_id')->all()));
    }

    /** @return list<int> Inhalte einer Art, die das Konto beobachtet */
    public function watched(?Customer $customer, string $type): array
    {
        if ($customer === null) {
            return [];
        }

        return array_values(array_map('intval', DB::table('community_subscriptions')->where(['customer_id' => $customer->id, 'subject_type' => $type, 'mode' => self::WATCH])
            ->pluck('subject_id')->all()));
    }
}
