<?php

declare(strict_types=1);

namespace Basalto\Core\Sso;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Models\Site;
use Basalto\Core\Models\SsoConnection;
use Basalto\Core\Models\SsoIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * TB 13 Scheibe 4 (M-25): Vom geprüften Konto beim Anbieter zum Backend-Benutzer.
 *
 * 1. Verknüpfung (Anbieter, Aussteller, Kennung) vorhanden → dieser Benutzer.
 * 2. Sonst der Benutzer mit der bestätigten E-Mail — beim ersten Mal wird verknüpft (Protokoll `sso.linked`).
 * 3. Sonst, nur wenn eingerichtet ({@see SsoConnection::autoCreateRole()}), ein neuer Benutzer mit der Rolle.
 *
 * Danach gilt immer: eine Rolle auf der Site (sonst abgewiesen — oder mit automatischer Anlage die Rolle dazu) und
 * kein deaktiviertes Konto.
 */
final class SsoSignIn
{
    public function __construct(private readonly Recorder $recorder) {}

    /** @throws SsoRefusal */
    public function resolve(SsoConnection $connection, Site $site, VerifiedIdentity $identity): Authenticatable&Model
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');
        $link = SsoIdentity::query()->where('provider', $identity->provider->value)->where('issuer', $identity->issuer)->where('subject', $identity->subject)->first();
        $user = $link === null ? null : $userModel::query()->find($link->user_id);
        if ($user === null) {
            foreach ($identity->emails as $email) {
                $user = $userModel::query()->where('email', $email)->first();
                if ($user !== null) {
                    break;
                }
            }
        }
        $autoRole = $connection->autoCreateRole();
        if ($user === null && $autoRole === null) {
            throw new SsoRefusal(SsoRefusal::UNKNOWN, $identity->email());
        }
        if ($user === null) {
            $user = $this->create($userModel, $identity);
            $this->recorder->record('sso.user_created', $user, null, ['provider' => $identity->provider->value, 'email' => $identity->email(), 'role' => $autoRole->value], $site, $user instanceof Authenticatable ? $user : null);
        }
        if (! $user instanceof Authenticatable) {
            abort(500, 'Benutzermodell ist nicht anmeldbar');
        }
        if ($user->getAttribute('deactivated_at') !== null) {
            throw new SsoRefusal(SsoRefusal::DEACTIVATED, $identity->email());
        }
        if ($site->roleKeyOf($user) === null) {
            if ($autoRole === null) {
                throw new SsoRefusal(SsoRefusal::NO_ROLE, $identity->email());
            }
            $site->users()->syncWithoutDetaching([$user->getKey() => ['role' => $autoRole->value]]);
            $this->recorder->record('sso.role_granted', $user, null, ['provider' => $identity->provider->value, 'role' => $autoRole->value], $site, $user);
        }
        $this->link($link, $user, $identity, $site);

        return $user;
    }

    /** Verknüpfung beim ersten Mal anlegen, sonst E-Mail und Zeitpunkt nachführen. */
    private function link(?SsoIdentity $link, Authenticatable&Model $user, VerifiedIdentity $identity, Site $site): void
    {
        if ($link !== null) {
            $link->forceFill(['email' => $identity->email(), 'last_login_at' => CarbonImmutable::now()])->save();

            return;
        }
        SsoIdentity::query()->create(['user_id' => (int) $user->getKey(), 'provider' => $identity->provider->value, 'issuer' => $identity->issuer,
            'subject' => $identity->subject, 'email' => $identity->email(), 'last_login_at' => CarbonImmutable::now()]);
        $this->recorder->record('sso.linked', $user, null, ['provider' => $identity->provider->value, 'email' => $identity->email()], $site, $user);
    }

    /** @param  class-string<Model>  $userModel */
    private function create(string $userModel, VerifiedIdentity $identity): Model
    {
        return DB::transaction(static function () use ($userModel, $identity): Model {
            $user = new $userModel;
            $user->forceFill(['name' => Str::limit($identity->name, 190, ''), 'email' => $identity->email(), 'password' => Hash::make(Str::random(64)), 'email_verified_at' => now()])->save();

            return $user;
        });
    }
}
