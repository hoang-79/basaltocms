<?php

declare(strict_types=1);

namespace Basalto\Modules\Members\Testdata;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use Basalto\Core\Testdata\TestdataRun;
use Basalto\Modules\Members\MemberStatus;
use Basalto\Modules\Members\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * Testdaten der Mitglieder: ein Teil der Kundenkonten ist Mitglied — aktiv (freigegeben), wartend (selbst
 * registriert oder eingeladen) und gesperrt —, damit Liste, Filter, Freigabe und Export echten Bestand haben.
 */
final class MembersTestdata
{
    public const KEY = 'mitglieder';

    public function seed(TestdataRun $run): void
    {
        $site = $run->site;
        $staff = DB::table('site_user')->where('site_id', $site->id)->orderBy('user_id')->value('user_id');
        foreach ($run->pool('alle') as $customer) {
            if (! $run->chance(70)) {
                continue;
            }
            $status = MemberStatus::from($run->weighted(['active' => 65, 'pending' => 25, 'blocked' => 10]));
            $created = $run->ago(300, 1);
            $invited = $status === MemberStatus::Pending && $run->chance(40);
            $member = Member::query()->withoutGlobalScopes()->firstOrNew(['site_id' => $site->id, 'customer_id' => $customer->id]);
            $member->forceFill(['status' => $status, 'invited_at' => $invited ? $created : null, 'invited_by' => $invited ? $staff : null,
                'approved_at' => $status === MemberStatus::Active ? $run->after($created, 72) : null, 'approved_by' => $status === MemberStatus::Active ? $staff : null,
                'blocked_at' => $status === MemberStatus::Blocked ? $run->after($created, 24 * 30) : null, 'created_at' => $created, 'updated_at' => $created])->save();
            $run->count('Mitglieder ('.$status->value.')');
        }
    }

    public function remove(Site $site): void
    {
        $customers = Customer::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('email', 'like', '%@'.TestdataRun::DOMAIN)->pluck('id')->all();
        Member::query()->withoutGlobalScopes()->whereIn('customer_id', $customers)->delete();
    }
}
