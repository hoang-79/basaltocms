<?php

declare(strict_types=1);

namespace Basalto\Core\Legal\Cancellation;

use Basalto\Core\Accounts\AccountRole;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\Site;
use Basalto\Core\Saas\Offboarding\Cancellation;
use Basalto\Core\Saas\Offboarding\Lifecycle;
use Basalto\Core\Saas\Plan;
use Basalto\Core\Saas\Saas;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Das SaaS-Abo als kündbarer Vertrag (CR-011 TB 59): zu finden über die E-Mail-Adresse von Besitzer oder Abrechnung,
 * gekündigt wie unter Verwaltung › Konto › Export und Kündigung ({@see Cancellation}) — auf das Ende des bezahlten
 * Monats, in der Testphase sofort. Kündbar nur auf Sites des Betreibers, nicht auf den Sites der Kundinnen und Kunden.
 */
final class SaasContractSource implements ContractSource
{
    public function key(): string
    {
        return 'saas';
    }

    public function label(): string
    {
        return 'SaaS-Abo (Basalto in der Cloud)';
    }

    public function hint(): string
    {
        return 'Adresse Ihrer Site oder Name des Kontos';
    }

    public function available(Site $site): bool
    {
        return Saas::enabled() && ! Saas::isCustomer($site->account);
    }

    public function find(Site $site, string $email, ?string $reference): array
    {
        $email = mb_strtolower(trim($email));
        $accounts = Account::query()
            ->whereIn('plan', array_keys((array) config('basalto.saas.plans', [])))
            ->whereHas('users', static fn (Builder $q) => $q->whereRaw('lower(email) = ?', [$email])
                ->whereIn('account_user.role', [AccountRole::Owner->value, AccountRole::Billing->value]))
            ->with('sites.domains')->orderBy('id')->get()
            ->filter(static fn (Account $account): bool => Lifecycle::state($account) === Lifecycle::ACTIVE);
        $reference = self::normalize($reference);
        if ($reference !== '') {
            $accounts = $accounts->filter(static fn (Account $account): bool => self::matches($account, $reference));
        }

        return array_values($accounts->all());
    }

    public function earliestEnd(Model $contract): CarbonImmutable
    {
        $account = self::account($contract);

        return self::lastDay(self::cancellation()->previewEnd($account), Lifecycle::timezone($account));
    }

    public function cancel(Model $contract): CarbonImmutable
    {
        $account = self::account($contract);
        $owner = self::owner($account);
        abort_if($owner === null, 409);
        self::cancellation()->cancel($account, $owner, false);
        $end = $account->ends_at ?? CarbonImmutable::now();

        return self::lastDay($end, Lifecycle::timezone($account));
    }

    public function revoke(Model $contract): bool
    {
        $account = self::account($contract);
        $owner = self::owner($account);
        if ($owner === null) {
            return false;
        }
        try {
            self::cancellation()->revoke($account, $owner);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    public function describe(Model $contract, string $locale): string
    {
        $account = self::account($contract);
        $plan = Plan::find($account->plan);

        return __('SaaS-Abo «:account» (Plan :plan)', ['account' => $account->name, 'plan' => $plan?->label() ?? $account->plan], $locale);
    }

    public function notes(Model $contract, string $locale): array
    {
        $account = self::account($contract);
        $dates = Lifecycle::dates($account);
        if ($dates['deletes'] === null) {
            return [];
        }

        return [__('Danach sind die Sites :days Tage gesperrt — der Export unter Verwaltung › Konto › Export und Kündigung geht weiter. Am :deletes löschen wir Sites und Medien.',
            ['days' => (string) $dates['lock_days'], 'deletes' => $dates['deletes']], $locale)];
    }

    /** Beim Booten angemeldet — der Dienst (mit dem Zahlungsweg) kommt erst beim Gebrauch aus dem Container. */
    private static function cancellation(): Cancellation
    {
        return app(Cancellation::class);
    }

    /** Wer im Namen des Kontos kündigt (für das Protokoll): Besitzer, sonst Abrechnung. */
    private static function owner(Account $account): ?Authenticatable
    {
        $id = $account->users()->wherePivotIn('role', [AccountRole::Owner->value, AccountRole::Billing->value])->orderBy('users.id')->first()?->getKey();

        return is_numeric($id) ? Auth::getProvider()->retrieveById((int) $id) : null;
    }

    private static function account(Model $contract): Account
    {
        abort_unless($contract instanceof Account, 500);

        return $contract;
    }

    /** Der letzte Tag vor dem Zeitpunkt des Endes (00:00 Uhr → Vortag), nie vor heute — als Datum in der Zeitzone der Sites. */
    private static function lastDay(CarbonImmutable $end, string $timezone): CarbonImmutable
    {
        $local = $end->timezone($timezone);
        $last = $local->subSecond();
        $today = CarbonImmutable::now($timezone)->startOfDay();

        return ($last->isBefore($today) ? $local : $last)->startOfDay();
    }

    private static function normalize(?string $reference): string
    {
        $reference = mb_strtolower(trim((string) $reference));
        $reference = (string) preg_replace('#^https?://#', '', $reference);

        return trim((string) preg_replace('#/.*$#', '', $reference));
    }

    private static function matches(Account $account, string $reference): bool
    {
        if (mb_strtolower($account->name) === $reference) {
            return true;
        }
        foreach ($account->sites as $site) {
            if ($site->handle === $reference || $site->domains->contains(static fn (Model $domain): bool => mb_strtolower((string) $domain->getAttribute('host')) === $reference)) {
                return true;
            }
        }

        return false;
    }
}
