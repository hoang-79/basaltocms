<?php

declare(strict_types=1);

namespace Basalto\Core\Saas;

use Basalto\Core\Accounts\AccountRole;
use Basalto\Core\Auth\LoginLinks;
use Basalto\Core\Install\Installer;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\SaasRegistration;
use Basalto\Core\Models\Site;
use Basalto\Core\Saas\Notifications\RegistrationCodeNotification;
use Basalto\Core\Saas\Notifications\RegistrationExistsNotification;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Support\BackendLocale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Selbstregistrierung auf der SaaS (TB 12 Scheibe 1, M-21, Mockup 22): Konto-Angaben mit Zustimmung → Mail-Code oder
 * Einmal-Link → Site mit Alias und Theme → Konto in der Testphase, Besitzer, Site live. Eine E-Mail gehört genau einem
 * Konto: Wer schon eines hat, bekommt statt des Codes einen Link zur Anmeldung — die Seite antwortet gleich.
 */
final class Registrar
{
    public function __construct(private readonly Installer $installer, private readonly SiteProvisioner $sites, private readonly LoginLinks $links) {}

    public function start(string $name, ?string $company, string $email, string $locale, ?string $ip): SaasRegistration
    {
        $email = mb_strtolower(trim($email));
        $registration = SaasRegistration::query()->where('email', $email)->whereNull('completed_at')->latest('id')->first() ?? new SaasRegistration(['email' => $email]);
        $registration->fill(['name' => $name, 'company' => $company, 'locale' => $locale, 'terms_accepted_at' => now(), 'ip_hash' => $ip === null ? null : hash('sha256', $ip.'|'.config('app.key'))]);
        $registration->forceFill(['verified_at' => null])->save();
        $this->send($registration);

        return $registration;
    }

    /** Code und Link (neu) senden — oder bei vorhandenem Konto den Link zur Anmeldung. */
    public function send(SaasRegistration $registration): void
    {
        $owner = self::existingAccountUser($registration->email);
        if ($owner !== null) {
            $registration->forceFill(['code_hash' => null, 'link_hash' => null])->save();
            if (method_exists($owner, 'notify')) {
                $owner->notify(new RegistrationExistsNotification($this->links->create((int) $owner->getKey(), 'login')));
            }

            return;
        }
        $codeMinutes = (int) app(Settings::class)->get('auth.code_minutes', (int) config('basalto.auth.code_minutes', 10));
        $linkMinutes = (int) app(Settings::class)->get('auth.link_minutes', (int) config('basalto.auth.link_minutes', 60));
        $secrets = $registration->issueSecrets($codeMinutes, $linkMinutes);
        Notification::route('mail', $registration->email)
            ->notify((new RegistrationCodeNotification($secrets['code'], route('basalto.saas.register.link', ['token' => $secrets['link']]), $codeMinutes))->locale($registration->locale));
    }

    /**
     * Konto, Besitzer und erste Site anlegen, dann die Site live schalten.
     *
     * @param  list<string>  $locales
     * @return array{account: Account, user: Model, site: Site, live: bool, errors: list<string>}
     */
    public function complete(SaasRegistration $registration, string $siteName, string $alias, array $locales, string $timezone, string $theme): array
    {
        abort_unless($registration->isVerified() && ! $registration->isCompleted(), 404);
        if (self::existingAccountUser($registration->email) !== null) {
            throw ValidationException::withMessages(['alias' => __('Zu dieser Adresse gibt es schon ein Konto — bitte melden Sie sich an.')]);
        }
        try {
            [$account, $user, $site] = DB::transaction(function () use ($registration, $siteName, $alias, $locales, $timezone, $theme): array {
                $account = Account::query()->create([
                    'name' => $registration->company !== null && $registration->company !== '' ? $registration->company : $registration->name,
                    'plan' => Account::PLAN_TRIAL, 'status' => 'active', 'instance' => Saas::instance(),
                    'trial_ends_at' => now()->addDays(Saas::trialDays()), 'terms_accepted_at' => $registration->terms_accepted_at,
                ]);
                $user = $this->installer->user($registration->name, $registration->email);
                if ($user->getAttribute('locale') === null && in_array($registration->locale, BackendLocale::LOCALES, true)) {
                    $user->forceFill(['locale' => $registration->locale])->save();
                }
                $account->users()->syncWithoutDetaching([$user->getKey() => ['role' => AccountRole::Owner->value]]);
                $site = $this->sites->build($account, $user, $siteName, $alias, $locales, $timezone, $theme);
                $registration->forceFill(['account_id' => $account->id, 'completed_at' => now()])->save();

                return [$account, $user, $site];
            });
        } catch (UniqueConstraintViolationException) {
            throw SiteProvisioner::aliasTaken($alias, $siteName);
        }

        return ['account' => $account, 'user' => $user, 'site' => $site] + $this->sites->publish($site);
    }

    /** Ein Benutzer mit dieser Adresse, der schon eine Rolle an einem Konto hat — sonst null. */
    public static function existingAccountUser(string $email): ?Model
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');
        $user = $userModel::query()->where('email', mb_strtolower(trim($email)))->first();
        if ($user === null || ! DB::table('account_user')->where('user_id', $user->getKey())->exists()) {
            return null;
        }

        return $user;
    }
}
