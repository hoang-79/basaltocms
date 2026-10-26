<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Social;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\FrontendAccounts\Notifications\SocialLinkedNotification;
use Basalto\Core\Models\Site;
use Basalto\Core\Security\StopForumSpam;
use Illuminate\Support\Str;

/**
 * TB 53: Vom geprüften Konto beim Anbieter zum Kundenkonto der Site.
 *
 * 1. Verknüpfung (Anbieter, Kennung) vorhanden → dieses Kundenkonto.
 * 2. Angemeldet und «Verknüpfen» gewählt → mit dem angemeldeten Konto verknüpfen.
 * 3. Sonst das Kundenkonto mit der bestätigten E-Mail — verknüpft und per Mail gemeldet (wie ein Code an dieselbe
 *    Adresse: wer die Adresse beim Anbieter bestätigt hat, besitzt sie).
 * 4. Sonst ein neues Konto — nur, wenn die Site neue Konten zulässt ({@see CustomerAuth::opensRegistration()}) und
 *    Stop Forum Spam (falls eingeschaltet) nichts meldet.
 */
final class SocialSignIn
{
    public function __construct(private readonly StopForumSpam $spam) {}

    /**
     * @return array{customer: Customer, created: bool, linked: bool}
     *
     * @throws SocialRefusal
     */
    public function resolve(Site $site, VerifiedAccount $account, ?Customer $current, string $lang, ?string $ip, string $accountUrl): array
    {
        $link = SocialIdentity::query()->where('site_id', $site->id)->where('provider', $account->provider->value)->where('subject', $account->subject)->first();
        $linked = $link === null ? null : Customer::query()->withoutGlobalScopes()->where('site_id', $site->id)->find($link->customer_id);
        if ($link !== null && $linked !== null) {
            if ($current !== null && $current->id !== $linked->id) {
                throw new SocialRefusal(SocialRefusal::TAKEN, $account->provider);
            }
            $link->forceFill(['email' => $account->email, 'last_login_at' => now()])->save();

            return ['customer' => $linked, 'created' => false, 'linked' => false];
        }
        $customer = $current ?? Customer::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('email', $account->email)->first();
        if ($customer !== null) {
            $this->link($site, $customer, $account);
            $customer->notify((new SocialLinkedNotification($account->provider->label(), $site->name, $accountUrl))->locale($customer->preferredLocale()));

            return ['customer' => $customer, 'created' => false, 'linked' => true];
        }
        if (! CustomerAuth::opensRegistration($site)) {
            throw new SocialRefusal(SocialRefusal::CLOSED, $account->provider);
        }
        if ($this->spam->listed($site, $account->email, $ip) !== null) {
            throw new SocialRefusal(SocialRefusal::SPAM, $account->provider);
        }
        $customer = Customer::query()->withoutGlobalScopes()->create(['site_id' => $site->id, 'email' => $account->email,
            'name' => $account->name === null ? null : Str::limit($account->name, 190, ''), 'locale' => $lang]);
        $this->link($site, $customer, $account);

        return ['customer' => $customer, 'created' => true, 'linked' => true];
    }

    private function link(Site $site, Customer $customer, VerifiedAccount $account): void
    {
        SocialIdentity::query()->where('site_id', $site->id)->where('provider', $account->provider->value)->where('subject', $account->subject)->delete();
        SocialIdentity::query()->create(['site_id' => $site->id, 'customer_id' => $customer->id, 'provider' => $account->provider->value,
            'subject' => $account->subject, 'email' => $account->email, 'last_login_at' => now()]);
    }
}
