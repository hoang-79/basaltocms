<?php

declare(strict_types=1);

namespace Basalto\Core\Extranet;

use Basalto\Core\Accounts\AccountRole;
use Basalto\Core\Invoices\Money;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\AccountInvoice;
use Basalto\Core\Models\SiteDomain;
use Basalto\Core\Saas\Plan;
use Basalto\Core\Saas\Usage;
use Basalto\Core\Support\Text;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Kunden der SaaS für die Kundenakte (CR-004, P-11): Konten mit Plan, Testphase, Kündigung, Sites, Nutzung und
 * Rechnungen. Filter für die Übersicht: «Testphase endet bald» und «gekündigt».
 *
 * @phpstan-import-type Row from CustomerSource
 * @phpstan-import-type Section from CustomerSource
 * @phpstan-import-type Card from CustomerSource
 */
final class SaasCustomers implements CustomerSource
{
    public const TRIAL_ENDING = 'trial_ending';

    public const CANCELLED = 'cancelled';

    /** Tage vor dem Ende, ab denen eine Testphase «bald» endet. */
    public const TRIAL_DAYS = 7;

    public function key(): string
    {
        return 'saas';
    }

    public function label(): string
    {
        return Text::t('SaaS-Konto');
    }

    public function filters(): array
    {
        return [self::TRIAL_ENDING => Text::t('Testphase endet bald'), self::CANCELLED => Text::t('Gekündigt')];
    }

    /** @return Builder<Account> */
    public static function query(?string $filter = null): Builder
    {
        $query = Account::query()->whereIn('plan', array_keys((array) config('basalto.saas.plans', [])))->whereNull('erased_at');

        return match ($filter) {
            self::TRIAL_ENDING => $query->where('plan', Account::PLAN_TRIAL)->where('trial_ends_at', '>', now())->where('trial_ends_at', '<=', now()->addDays(self::TRIAL_DAYS)),
            self::CANCELLED => $query->whereNotNull('cancelled_at'),
            default => $query,
        };
    }

    public function list(?string $search, ?string $filter): array
    {
        $rows = [];
        foreach (self::query($filter)->with('sites')->orderByDesc('id')->limit(500)->get() as $account) {
            $owner = self::owner($account);
            $email = (string) ($owner?->getAttribute('email') ?? '');
            if ($search !== null && $search !== '' && ! str_contains(mb_strtolower($account->name.' '.$email.' '.$account->sites->pluck('handle')->implode(' ')), mb_strtolower($search))) {
                continue;
            }
            $tags = [];
            if ($account->plan === Account::PLAN_TRIAL && $account->trial_ends_at !== null) {
                $tags[] = $account->trial_ends_at->isPast() ? Text::t('Testphase abgelaufen') : Text::t('Testphase bis :date', ['date' => $account->trial_ends_at->format('d.m.Y')]);
            }
            if ($account->cancelled_at !== null) {
                $tags[] = Text::t('gekündigt');
            }
            $rows[] = ['source' => $this->key(), 'id' => $account->id, 'name' => $account->name, 'email' => $email, 'company' => null,
                'meta' => trim(self::planLabel($account->plan).' · '.trans_choice(':count Site|:count Sites', $account->sites->count(), ['count' => $account->sites->count()]).' · '.$account->sites->pluck('handle')->implode(', '), ' ·'),
                'created' => $account->created_at?->format('d.m.Y'), 'tags' => $tags];
        }

        return $rows;
    }

    public function find(int $id): ?array
    {
        $account = self::query()->find($id);
        if ($account === null) {
            return null;
        }
        $owner = self::owner($account);
        $facts = [[Text::t('Plan'), self::planLabel($account->plan)], [Text::t('Instanz'), (string) ($account->instance ?? '—')], [Text::t('Status'), $account->status]];
        if ($account->trial_ends_at !== null && $account->plan === Account::PLAN_TRIAL) {
            $facts[] = [Text::t('Testphase bis'), $account->trial_ends_at->format('d.m.Y')];
        }
        if ($account->cancelled_at !== null) {
            $facts[] = [Text::t('Gekündigt am'), $account->cancelled_at->format('d.m.Y')];
            $facts[] = [Text::t('Laufzeit bis'), $account->ends_at?->format('d.m.Y') ?? '—'];
            $facts[] = [Text::t('Löschung am'), $account->delete_after?->format('d.m.Y') ?? '—'];
        }

        return ['source' => $this->key(), 'id' => $account->id, 'name' => $account->name, 'email' => (string) ($owner?->getAttribute('email') ?? ''), 'company' => null, 'facts' => $facts];
    }

    public function sectionsFor(string $email): array
    {
        $sections = [];
        $accounts = self::query()->whereHas('users', static fn (Builder $q) => $q->where('email', $email))->with('sites')->get();
        foreach ($accounts as $account) {
            $sites = [];
            foreach ($account->sites as $site) {
                $hosts = SiteDomain::query()->where('site_id', $site->id)->orderByDesc('is_primary')->pluck('host')->implode(', ');
                $sites[] = [$site->name, $site->handle, $hosts !== '' ? $hosts : '—'];
            }
            $sections[] = ['title' => Text::t('SaaS-Konto «:name» · :plan', ['name' => $account->name, 'plan' => self::planLabel($account->plan)]), 'columns' => [Text::t('Site'), Text::t('Alias'), Text::t('Domains')],
                'rows' => $sites, 'empty' => Text::t('Keine Sites')];
            $sections[] = ['title' => Text::t('Nutzung'), 'columns' => [Text::t('Kontingent'), Text::t('Genutzt'), Text::t('Grenze')],
                'rows' => array_map(static fn (array $row): array => [$row['label'], self::amount($row['used'], $row['unit']), self::amount($row['limit'], $row['unit'])], Usage::of($account)), 'empty' => '—'];
            $invoices = AccountInvoice::query()->where('account_id', $account->id)->orderByDesc('id')->limit(50)->get();
            $sections[] = ['title' => Text::t('SaaS-Rechnungen'), 'columns' => [Text::t('Nummer'), Text::t('Datum'), Text::t('Betrag'), Text::t('Status')],
                'rows' => $invoices->map(static fn (AccountInvoice $i): array => [$i->number, $i->issued_at->format('d.m.Y'), Money::format($i->total, $i->currency, app()->getLocale()), SaasInvoices::statusLabel($i)])->values()->all(),
                'empty' => Text::t('Noch keine Rechnungen')];
        }

        return $sections;
    }

    public static function owner(Account $account): ?Model
    {
        return $account->users()->wherePivot('role', AccountRole::Owner->value)->orderBy('account_user.created_at')->first();
    }

    public static function planLabel(string $plan): string
    {
        return $plan === Account::PLAN_TRIAL ? Text::t('Testphase') : (Plan::find($plan)?->label() ?? $plan);
    }

    private static function amount(int $value, string $unit): string
    {
        return $unit === 'bytes' ? number_format($value / 1048576, 0, '.', "'").' MB' : (string) $value;
    }
}
