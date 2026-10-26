<?php

declare(strict_types=1);

namespace Basalto\Core\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifications;

/**
 * Sprachen der Oberfläche (N-01): Deutsch ist die Quelle, dazu EN/IT/FR. Die Sprache eines Benutzers steht in
 * `users.locale` (Profil); Gäste bekommen die Sprache aus `Accept-Language`, Mails gehen in der Sprache der Empfängerin.
 */
final class BackendLocale
{
    /** @var list<string> */
    public const LOCALES = ['de', 'en', 'it', 'fr'];

    /** Die Sprache aus dem Profil — null, wenn keine oder eine, die die Oberfläche nicht spricht. */
    public static function of(mixed $user): ?string
    {
        $locale = $user instanceof Model ? $user->getAttribute('locale') : null;

        return is_string($locale) && in_array($locale, self::LOCALES, true) ? $locale : null;
    }

    /**
     * Adressen nach der Sprache ihrer Konten: wer kein Konto (oder keine Sprache) hat, bekommt `$fallback`
     * (sonst die Sprache der laufenden Anfrage).
     *
     * @param  list<string>  $emails
     * @return array<string, list<string>> Sprache → Adressen
     */
    public static function group(array $emails, ?string $fallback = null): array
    {
        $fallback ??= app()->getLocale();
        $locales = [];
        if ($emails !== []) {
            /** @var class-string<Model> $model */
            $model = config('auth.providers.users.model');
            foreach ($model::query()->whereIn('email', $emails)->get(['email', 'locale']) as $user) {
                $locales[(string) $user->getAttribute('email')] = self::of($user);
            }
        }
        $groups = [];
        foreach ($emails as $email) {
            $groups[$locales[$email] ?? $fallback][] = $email;
        }

        return $groups;
    }

    /**
     * Eine Mail an Adressen ohne Notifiable: je Sprache ein Versand, sodass jede Empfängerin ihre Sprache liest.
     *
     * @param  list<string>  $emails
     */
    public static function notify(array $emails, Notification $notification, ?string $fallback = null): void
    {
        foreach (self::group($emails, $fallback) as $locale => $group) {
            Notifications::route('mail', $group)->notifyNow((clone $notification)->locale($locale));
        }
    }
}
