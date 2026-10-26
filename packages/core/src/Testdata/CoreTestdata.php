<?php

declare(strict_types=1);

namespace Basalto\Core\Testdata;

use App\Models\User;
use Basalto\Core\Access\RoleTemplates;
use Basalto\Core\Accounts\AccountRole;
use Basalto\Core\Community\CommunityProfile;
use Basalto\Core\Community\Conversations;
use Basalto\Core\Community\Covers;
use Basalto\Core\Community\Mounts;
use Basalto\Core\Community\Profiles;
use Basalto\Core\Community\Relations;
use Basalto\Core\Demo\DemoMode;
use Basalto\Core\Extranet\Extranet;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\AccountInvoice;
use Basalto\Core\Models\ExtranetMember;
use Basalto\Core\Models\ExtranetNote;
use Basalto\Core\Models\Site;
use Basalto\Core\Saas\Billing\Invoicer;
use Basalto\Core\Saas\Saas;
use Basalto\Core\Support\Avatars;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Testdaten des Kerns: Team der Site mit allen Rollen, Team im Extranet (auf der Site des Herstellers), viele
 * Kundenkonten in allen Varianten, Community-Profile mit Bildern, Folgen und Blockieren, Konversationen sowie
 * SaaS-Konten mit Rechnungen für die Kundenakte. Die Kundenkonten landen in den Töpfen des Laufs für die Module.
 */
final class CoreTestdata
{
    public const KEY = 'kern';

    /** @var list<array{0: string, 1: string, 2: string}> Vorname, Nachname, Rolle auf der Site */
    private const TEAM = [['Sandra', 'Keller', RoleTemplates::ADMINISTRATOR], ['Marco', 'Bernasconi', RoleTemplates::EDITOR], ['Petra', 'Huber', RoleTemplates::EDITOR],
        ['Jonas', 'Frei', RoleTemplates::APPROVER], ['Claire', 'Favre', RoleTemplates::TRANSLATOR]];

    /** @var list<array{0: string, 1: string, 2: string}> Team im Extranet */
    private const EXTRANET = [['Nadia', 'Steiner', RoleTemplates::STAFF], ['Luca', 'Rossi', RoleTemplates::STAFF], ['Monika', 'Gerber', RoleTemplates::ACCOUNTING]];

    private const NICKNAMES = ['pixelfan', 'Lava-Lisa', 'Basaltkopf', 'webwerker', 'Seebueb', 'CodeKatze', 'theme_tüftler', 'Bergler', 'Nachtschicht', 'Gipfelstürmerin'];

    public function __construct(private readonly Profiles $profiles, private readonly Relations $relations, private readonly Conversations $conversations, private readonly Mounts $mounts) {}

    public function seed(TestdataRun $run): void
    {
        $site = $run->site;
        $team = $this->team($run);
        $customers = $this->customers($run);
        if ($this->mounts->options($site) !== []) {
            $this->community($run, $customers, $team);
        }
        // Das Extranet gehört dem Hersteller — nie in der Demo
        if (Extranet::enabled() && ! DemoMode::enabled() && Extranet::vendorSite()?->id === $site->id) {
            $this->extranet($run, $customers);
        }
        // TB 54: Seitenaufrufe für die Statistik — eigener Zufallsstrom, die übrigen Testdaten bleiben gleich
        app(StatisticsTestdata::class)->seed($site, $run->scale);
    }

    public function remove(Site $site): void
    {
        $domain = '%@'.TestdataRun::DOMAIN;
        $customerIds = Customer::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('email', 'like', $domain)->pluck('id')->all();
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');
        $userIds = $userModel::query()->where('email', 'like', $domain)->pluck('id')->all();
        foreach (CommunityProfile::query()->withoutGlobalScopes()->whereIn('customer_id', $customerIds)->get() as $profile) {
            Storage::disk()->deleteDirectory('community-avatars/'.$profile->id);
            Storage::disk()->deleteDirectory('community-covers/'.$profile->id);
        }
        DB::table('community_conversations')->where('site_id', $site->id)->whereIn('customer_id', $customerIds)->delete();
        DB::table('notifications')->where('notifiable_type', (new Customer)->getMorphClass())->whereIn('notifiable_id', $customerIds)->delete();
        Customer::query()->withoutGlobalScopes()->whereIn('id', $customerIds)->delete();
        if ($userIds !== []) {
            $accounts = DB::table('account_user')->whereIn('user_id', $userIds)->where('role', AccountRole::Owner->value)->pluck('account_id')->all();
            $installation = Account::query()->orderBy('id')->value('id');
            $saas = Account::query()->whereIn('id', $accounts)->where('id', '!=', $installation)->whereDoesntHave('sites')->pluck('id')->all();
            AccountInvoice::query()->whereIn('account_id', $saas)->delete();
            Account::query()->whereIn('id', $saas)->delete();
            DB::table('notifications')->where('notifiable_type', (new $userModel)->getMorphClass())->whereIn('notifiable_id', $userIds)->delete();
            ExtranetMember::query()->whereIn('user_id', $userIds)->delete();
            foreach ($userModel::query()->whereIn('id', $userIds)->get() as $user) {
                Avatars::delete($user);
                $user->delete();
            }
        }
        ExtranetNote::query()->where('email', 'like', $domain)->delete();
        app(StatisticsTestdata::class)->remove($site);
    }

    /** @return list<Model> Team der Site (mit Konto für die Community) */
    private function team(TestdataRun $run): array
    {
        $site = $run->site;
        $multilingual = count($site->localeCodes()) > 1;
        $out = [];
        foreach (self::TEAM as [$first, $last, $role]) {
            if ($role === RoleTemplates::TRANSLATOR && ! $multilingual) {
                continue;
            }
            $user = $this->user($first, $last);
            $site->users()->syncWithoutDetaching([$user->getKey() => ['role' => $role]]);
            $out[] = $user;
            $run->count('Team der Site');
        }

        return $out;
    }

    private function user(string $first, string $last): Model
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');
        $email = TestdataRun::email(TestdataText::local($first, $last, 0));
        $user = $userModel::query()->where('email', $email)->first();
        if ($user === null) {
            // Ohne benutzbares Passwort: angemeldet wird mit Code per Mail (geht ins Log)
            $user = new $userModel;
            $user->forceFill(['name' => $first.' '.$last, 'email' => $email, 'password' => Hash::make(Str::random(48)), 'email_verified_at' => now()])->save();
        }

        return $user;
    }

    /** @return list<Customer> */
    private function customers(TestdataRun $run): array
    {
        $site = $run->site;
        $locales = $site->localeCodes();
        $default = $site->default_locale;
        $weights = [$default => 70] + array_fill_keys(array_values(array_diff($locales, [$default])), 10);
        $all = [];
        $total = $run->times(30);
        for ($i = 0; $i < $total; $i++) {
            $tricky = $i < count(TestdataText::TRICKY_NAMES) && $i % 3 === 2;
            [$first, $last] = TestdataText::name($run);
            $name = match (true) {
                $tricky => TestdataText::TRICKY_NAMES[intdiv($i, 3)] ?? $first.' '.$last,
                $i % 17 === 5 => null,
                default => $first.' '.$last,
            };
            $email = TestdataRun::email(TestdataText::local($first, $last, $i));
            $customer = Customer::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('email', $email)->first();
            if ($customer === null) {
                $place = $run->pick(TestdataText::PLACES);
                $address = $run->chance(45);
                $created = $i < 3 ? $run->ago(2) : $run->ago(420, 3);
                $customer = new Customer(['site_id' => $site->id, 'email' => $email, 'name' => $name, 'locale' => $run->weighted($weights),
                    'company' => $run->chance(25) ? $run->pick(TestdataText::COMPANIES) : null,
                    'street' => $address ? $run->pick(TestdataText::STREETS).' '.$run->int(1, 120) : null, 'postal_code' => $address ? $place[0] : null,
                    'city' => $address ? $place[1] : null, 'country' => $address ? $place[2] : null]);
                $customer->forceFill(['created_at' => $created, 'updated_at' => $created,
                    'last_login_at' => $run->chance(80) ? $run->after($created, 24 * 90) : null])->save();
                $run->count('Kundenkonten');
            }
            $all[] = $customer;
            if ($i < 3) {
                $run->remember('neu', [$customer]);
            }
        }
        $run->remember('alle', $all);

        return $all;
    }

    /**
     * @param  list<Customer>  $customers
     * @param  list<Model>  $team
     */
    private function community(TestdataRun $run, array $customers, array $team): void
    {
        $site = $run->site;
        $withImages = TestdataImages::available();
        $active = [];
        $showcases = 0;
        foreach ($customers as $i => $customer) {
            if ($customer->name === null || $run->chance(12)) {
                $run->remember('ohne_profil', [$customer]);

                continue;
            }
            $profile = $this->profiles->of($customer);
            if ($profile === null) {
                $wanted = match (true) {
                    $i % 9 === 4 => $run->pick(self::NICKNAMES),
                    $i % 5 === 1 => Str::before((string) $customer->name, ' ').' '.mb_substr(Str::afterLast((string) $customer->name, ' '), 0, 1).'.',
                    default => trim((string) $customer->name),
                };
                $wanted = mb_substr($wanted !== '' ? $wanted : 'Mitglied', 0, 40);
                $name = $this->profiles->nameError($site, $wanted) === null ? $wanted : $this->profiles->suggestion($site, $wanted);
                // Zwei Profile sind vollständig — für Hilfe-Bilder und Funktionsschau; nicht aus den drei neuen Konten
                $showcase = $i >= 3 && $showcases < 2;
                $showcases += (int) $showcase;
                $profile = $this->profiles->save($customer, $name, $showcase || $run->chance(25) ? $run->pick(['Grüsse aus '.$run->pick(TestdataText::PLACES)[1], 'Basalto seit Version 1.0', '— gesendet vom Velo aus 🚲', TestdataText::sentence($run)]) : null);
                $profile->forceFill(['about' => $showcase || $run->chance(30) ? TestdataText::paragraph($run, $customer->locale ?? 'de') : null,
                    'last_activity_at' => $run->chance(8) ? $run->now()->subMinutes($run->int(0, 9)) : $run->after(CarbonImmutable::parse($customer->created_at), 24 * 120),
                    'created_at' => $customer->created_at])->save();
                if ($withImages && ($showcase || $run->chance(35))) {
                    Avatars::store($profile, TestdataImages::avatar($run, mb_substr($name, 0, 1).mb_substr(Str::afterLast($name, ' '), 0, 1)), 'community-avatars');
                }
                if ($withImages && ($showcase || $run->chance(10))) {
                    Covers::store($profile, TestdataImages::cover($run));
                }
                $run->count('Community-Profile');
            }
            $active[] = $customer;
        }
        $run->remember('aktiv', $active);
        // Moderation aus der Community (FO-13): ein paar aktive Mitglieder; der Rest bleibt gewöhnlich
        $run->remember('moderation', array_slice($active, 3, min(2 + $run->scale, max(0, count($active) - 3))));
        foreach ($team as $user) {
            if ($user instanceof User) {
                $profile = $this->profiles->linkTeam($user, $site);
                $customer = $profile->customer;
                if ($customer instanceof Customer) {
                    $run->remember('team', [$customer]);
                }
            }
        }
        foreach ($active as $customer) {
            foreach ($run->picks($active, $run->int(0, 5)) as $other) {
                if ($other->id !== $customer->id) {
                    $this->relations->follow($customer, $other);
                    $run->count('Folgen');
                }
            }
        }
        foreach (array_slice($active, 0, max(1, intdiv(count($active), 15))) as $customer) {
            $other = $run->pick($active);
            if ($other->id !== $customer->id) {
                $this->relations->block($customer, $other);
                $run->count('Blockiert');
            }
        }
        $this->conversations($run, $active);
    }

    /** @param  list<Customer>  $active */
    private function conversations(TestdataRun $run, array $active): void
    {
        if (count($active) < 3) {
            return;
        }
        $subjects = ['Frage zu Ihrem Theme', 'Treffen an der Basalto-Tagung?', 'Ihre Antwort im Forum', 'Zusammenarbeit an einem Plugin', 'Danke für die Hilfe!',
            'Ein sehr langer Betreff, der in der Liste der Konversationen bestimmt nicht auf eine Zeile passt und umbrechen muss', 'Re: Re: Re: Fwd: Termin'];
        for ($c = 0; $c < $run->times(4); $c++) {
            $people = $run->picks($active, $run->int(2, 4));
            $from = $people[0];
            $to = array_slice($people, 1);
            $to = array_values(array_filter($to, fn (Customer $p): bool => $this->conversations->refusing($from, [$p]) === []));
            if ($to === []) {
                continue;
            }
            $conversation = $this->conversations->start($run->site, $from, $to, $run->pick($subjects), TestdataText::doc($run, ['p']));
            $start = $run->ago(90, 1);
            DB::table('community_conversation_messages')->where('conversation_id', $conversation->id)->update(['created_at' => $start, 'updated_at' => $start]);
            $last = $start;
            $count = 1;
            for ($n = $run->int(0, 7); $n > 0; $n--) {
                $sender = $run->pick([$from, ...$to]);
                $message = $this->conversations->reply($run->site, $conversation, $sender, TestdataText::doc($run, $run->chance(20) ? ['p', 'quote'] : ['p']));
                $last = $run->after($last, 30);
                DB::table('community_conversation_messages')->where('id', $message->id)->update(['created_at' => $last, 'updated_at' => $last]);
                $count++;
            }
            DB::table('community_conversations')->where('id', $conversation->id)->update(['created_at' => $start, 'last_message_at' => $last]);
            $run->count('Konversationen');
            $run->count('Nachrichten', $count);
        }
    }

    /** @param  list<Customer>  $customers */
    private function extranet(TestdataRun $run, array $customers): void
    {
        $staff = [];
        foreach (self::EXTRANET as [$first, $last, $role]) {
            $user = $this->user($first, $last);
            ExtranetMember::query()->updateOrCreate(['user_id' => $user->getKey()], ['role' => $role]);
            $staff[] = $user;
            $run->count('Team im Extranet');
        }
        foreach ($run->picks($customers, $run->times(4)) as $customer) {
            ExtranetNote::query()->create(['email' => $customer->email, 'user_id' => $run->pick($staff)->getKey(),
                'body' => $run->pick(['Hat am Telefon nach Agentur-Rabatt gefragt.', 'Rechnung zweimal gemahnt — zahlt jeweils am Monatsende.', 'Sehr zufrieden, empfiehlt uns weiter.',
                    'Möchte die Daten aus WordPress übernehmen; Termin für Umzug vereinbaren.', TestdataText::paragraph($run)])]);
            $run->count('Notizen im Extranet');
        }
        if (Saas::enabled()) {
            $this->saas($run);
        }
    }

    /** SaaS-Konten in allen Zuständen: Testphase (bald zu Ende, abgelaufen), Start, Standard, Pro, gekündigt. */
    private function saas(TestdataRun $run): void
    {
        $states = [['trial', 12, null], ['trial', 3, null], ['trial', -2, null], ['start', null, null], ['standard', null, null], ['pro', null, null], ['standard', null, 20], ['start', null, 5]];
        $invoicer = app(Invoicer::class);
        foreach (array_slice($states, 0, min(count($states), 4 + $run->scale * 2)) as $n => [$plan, $trialDays, $cancelledDays]) {
            [$first, $last] = TestdataText::name($run);
            $owner = $this->user($first, $last.' '.($n + 1));
            if (DB::table('account_user')->where('user_id', $owner->getKey())->exists()) {
                continue;
            }
            $place = $run->pick(TestdataText::PLACES);
            $account = Account::query()->create(['name' => $run->pick(TestdataText::COMPANIES).' ('.($n + 1).')', 'plan' => $plan,
                'trial_ends_at' => $trialDays === null ? null : now()->addDays($trialDays), 'cancelled_at' => $cancelledDays === null ? null : now()->subDays($cancelledDays),
                'billing' => ['locale' => 'de', 'address' => ['company' => null, 'name' => $first.' '.$last, 'street' => $run->pick(TestdataText::STREETS).' 4', 'postal_code' => $place[0],
                    'city' => $place[1], 'country' => $place[2], 'email' => (string) $owner->getAttribute('email')]]]);
            $account->users()->attach($owner->getKey(), ['role' => AccountRole::Owner->value]);
            $run->count('SaaS-Konten');
            if ($plan === 'trial') {
                continue;
            }
            foreach (range(1, $run->int(1, 4)) as $month) {
                try {
                    $invoice = $invoicer->issueQr($account, CarbonImmutable::today()->startOfMonth()->subMonthsNoOverflow($month));
                } catch (Throwable) {
                    // Ohne Rechnungssteller in der Konfiguration keine QR-Rechnung — dann eben ohne
                    break;
                }
                if ($month > 1 || $run->chance(50)) {
                    $invoice->forceFill(['status' => AccountInvoice::STATUS_PAID, 'paid_at' => now()->subDays($month * 28)])->save();
                }
                $run->count('SaaS-Rechnungen');
            }
        }
    }
}
