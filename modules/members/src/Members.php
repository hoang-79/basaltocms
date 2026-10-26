<?php

declare(strict_types=1);

namespace Basalto\Modules\Members;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Backend\Backend;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\FrontendAccounts\CustomerLogin;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\FrontendAccounts\LoginForm;
use Basalto\Core\Models\Site;
use Basalto\Core\Models\SiteDomain;
use Basalto\Core\Notifications\Notify;
use Basalto\Core\Settings\Settings;
use Basalto\Modules\Members\Models\Member;
use Basalto\Modules\Members\Notifications\MemberApprovedNotification;
use Basalto\Modules\Members\Notifications\MemberInvitationNotification;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Die Mitglieder einer Site (M-23): wer Mitglied ist, beitreten (offen sofort, mit Freigabe wartend, auf Einladung
 * gar nicht), einladen, freigeben, sperren, entfernen — jede Änderung im Protokoll. Das Konto dahinter ist das
 * Frontend-Konto des Kerns; eine Mitgliedschaft löschen lässt das Konto stehen (Lizenzverkauf, Marktplatz).
 */
final class Members
{
    public const MODULE = 'members';

    /** Der Pfad der Anmeldeseite (frei wählbar, sonst die erste geschützte Seite). */
    public const LOGIN_PATH = 'members.login_path';

    public function __construct(private readonly Settings $settings, private readonly Backend $backend, private readonly Recorder $recorder) {}

    public function enabled(Site $site): bool
    {
        return $this->backend->moduleEnabled(self::MODULE, $site);
    }

    public function registration(Site $site): Registration
    {
        return Registration::tryFrom((string) $this->settings->get(Registration::SETTING, Registration::Approval->value, $site)) ?? Registration::Approval;
    }

    public function of(Customer $customer): ?Member
    {
        return Member::query()->withoutGlobalScopes()->where('site_id', $customer->site_id)->where('customer_id', $customer->id)->first();
    }

    /** Die Mitgliedschaft der angemeldeten Person auf dieser Site — oder null. */
    public function current(Site $site): ?Member
    {
        $customer = CustomerAuth::current();

        return $customer === null || $customer->site_id !== $site->id ? null : $this->of($customer);
    }

    /**
     * Beitreten nach der Anmeldung oder über «Mitglied werden»: offen → aktiv, mit Freigabe → wartend, auf Einladung →
     * nichts. Wer schon Mitglied ist (auch gesperrt), bleibt, wie er ist.
     */
    public function join(Customer $customer, Site $site): ?Member
    {
        $existing = $this->of($customer);
        if ($existing !== null || $customer->site_id !== $site->id) {
            return $existing;
        }
        $status = match ($this->registration($site)) {
            Registration::Open => MemberStatus::Active,
            Registration::Approval => MemberStatus::Pending,
            Registration::Invite => null,
        };
        if ($status === null) {
            return null;
        }
        $member = Member::query()->withoutGlobalScopes()->create(['site_id' => $site->id, 'customer_id' => $customer->id, 'status' => $status,
            'approved_at' => $status === MemberStatus::Active ? now() : null]);
        $this->recorder->record('members.joined', $member, null, ['email' => $customer->email, 'status' => $status->value], $site);
        if ($status === MemberStatus::Pending) {
            Notify::make('members.pending')->title(':email möchte Mitglied werden', ['email' => (string) $customer->email])
                ->titleMany(':count Mitgliedschaften warten auf Freigabe')->url(route('basalto.members.index', ['status' => MemberStatus::Pending->value], false), 'Mitglieder öffnen')
                ->collapse('members')->toArea($site)->send();
        }

        return $member;
    }

    /** Einladen: Konto anlegen (falls nötig), Mitgliedschaft aktiv, Mail mit dem Weg zur Anmeldung. Gesperrte bleiben gesperrt, ohne Mail. */
    public function invite(Site $site, string $email, ?string $name, string $locale, ?Authenticatable $by): Member
    {
        $email = CustomerLogin::normalize($email);
        $customer = Customer::query()->withoutGlobalScopes()->firstOrCreate(['site_id' => $site->id, 'email' => $email], ['locale' => $locale, 'name' => $name]);
        if ($name !== null && ($customer->name === null || trim($customer->name) === '')) {
            $customer->forceFill(['name' => $name])->save();
        }
        $member = $this->of($customer);
        $byId = $by === null ? null : (int) $by->getAuthIdentifier();
        if ($member === null) {
            $member = Member::query()->withoutGlobalScopes()->create(['site_id' => $site->id, 'customer_id' => $customer->id, 'status' => MemberStatus::Active,
                'invited_at' => now(), 'invited_by' => $byId, 'approved_at' => now(), 'approved_by' => $byId]);
        } elseif ($member->status === MemberStatus::Pending) {
            $member->forceFill(['status' => MemberStatus::Active, 'invited_at' => now(), 'invited_by' => $byId, 'approved_at' => now(), 'approved_by' => $byId])->save();
        } else {
            $member->forceFill(['invited_at' => now(), 'invited_by' => $byId])->save();
        }
        if ($member->status !== MemberStatus::Blocked) {
            $customer->notify((new MemberInvitationNotification($site->name, $this->loginUrl($site, $customer->preferredLocale())))->locale($customer->preferredLocale()));
        }
        $this->recorder->record('members.invited', $member, null, ['email' => $email, 'status' => $member->status->value], $site, $by);

        return $member;
    }

    public function approve(Member $member, Site $site, ?Authenticatable $by): void
    {
        $before = $member->status;
        $member->forceFill(['status' => MemberStatus::Active, 'approved_at' => now(), 'approved_by' => $by === null ? null : (int) $by->getAuthIdentifier(), 'blocked_at' => null])->save();
        if ($before === MemberStatus::Pending) {
            $customer = $member->customer;
            $customer->notify((new MemberApprovedNotification($site->name, $this->loginUrl($site, $customer->preferredLocale())))->locale($customer->preferredLocale()));
        }
        $this->recorder->record('members.approved', $member, ['status' => $before->value], ['status' => MemberStatus::Active->value], $site, $by);
    }

    public function block(Member $member, Site $site, ?Authenticatable $by): void
    {
        $before = $member->status;
        $member->forceFill(['status' => MemberStatus::Blocked, 'blocked_at' => now()])->save();
        $this->recorder->record('members.blocked', $member, ['status' => $before->value], ['status' => MemberStatus::Blocked->value], $site, $by);
    }

    /** Mitgliedschaft löschen — das Frontend-Konto bleibt (es kann Lizenzen oder Einträge im Marktplatz tragen). */
    public function remove(Member $member, Site $site, ?Authenticatable $by): void
    {
        $this->recorder->record('members.removed', $member, ['status' => $member->status->value], null, $site, $by);
        $member->delete();
    }

    /**
     * Wo man sich anmeldet: die eingestellte Seite (etwa eine mit `{% members.account %}`), sonst die Anmeldeseite des
     * Kerns `/account/login` in der Sprache — nie die Adresse einer geschützten Seite, die verriete sie Gästen.
     */
    public function loginPath(Site $site, string $locale): string
    {
        $path = $this->settings->get(self::LOGIN_PATH, null, $site);
        if (is_string($path) && str_starts_with($path, '/') && ! str_starts_with($path, '//')) {
            return $path;
        }

        return Html::url('/account/login', $locale === $site->default_locale ? [] : ['sprache' => $locale]);
    }

    /** Die Anmeldung als absolute Adresse (für Mails). */
    public function loginUrl(Site $site, string $locale): string
    {
        return self::siteUrl($site).$this->loginPath($site, $locale).'#'.LoginForm::ANCHOR;
    }

    public static function siteUrl(Site $site): string
    {
        $host = SiteDomain::query()->withoutGlobalScopes()->where('site_id', $site->id)->orderByDesc('is_primary')->value('host');

        return is_string($host) && $host !== '' ? 'https://'.$host : rtrim((string) config('app.url'), '/');
    }
}
