<?php

declare(strict_types=1);

namespace Basalto\Core\Notifications;

use Illuminate\Database\Eloquent\Model;

/**
 * Was eine Person bekommen will (TB 27) — in `users.preferences.notifications`: ein Hauptschalter, je ein Schalter für
 * Glocke und E-Mail und je Art beide Kanäle. Nichts eingestellt heisst: die Vorgabe der Art gilt. Pflicht-Mails
 * (`mailRequired`) kommen immer, auch mit Hauptschalter aus.
 *
 * @phpstan-type Stored array{enabled: bool, bell: bool, mail: bool, types: array<string, array{bell?: bool, mail?: bool}>}
 */
final class NotificationSettings
{
    /** @return Stored */
    public static function of(?Model $user): array
    {
        $prefs = $user?->getAttribute('preferences');
        if (is_string($prefs)) {
            $prefs = json_decode($prefs, true);
        }
        $stored = is_array($prefs) && is_array($prefs['notifications'] ?? null) ? $prefs['notifications'] : [];
        $types = [];
        foreach (is_array($stored['types'] ?? null) ? $stored['types'] : [] as $key => $channels) {
            if (! is_string($key) || ! is_array($channels)) {
                continue;
            }
            $types[$key] = array_filter(['bell' => $channels['bell'] ?? null, 'mail' => $channels['mail'] ?? null], 'is_bool');
        }

        return [
            'enabled' => is_bool($stored['enabled'] ?? null) ? $stored['enabled'] : true,
            'bell' => is_bool($stored['bell'] ?? null) ? $stored['bell'] : true,
            'mail' => is_bool($stored['mail'] ?? null) ? $stored['mail'] : true,
            'types' => $types,
        ];
    }

    /**
     * Die Kanäle einer Art für eine Person, nach Hauptschalter, Kanal-Schalter und Wahl je Art.
     *
     * @return array{bell: bool, mail: bool}
     */
    public static function channels(?Model $user, NotificationType $type): array
    {
        $settings = self::of($user);
        $chosen = $settings['types'][$type->key] ?? [];
        $bell = $settings['enabled'] && $settings['bell'] && ($chosen['bell'] ?? $type->bell);
        $mail = $type->mailRequired || ($type->mailAvailable && $settings['enabled'] && $settings['mail'] && ($chosen['mail'] ?? $type->mail));

        return ['bell' => $bell, 'mail' => $mail];
    }

    /**
     * Für das Profil: die Schalter mit der Wahl der Person (oder der Vorgabe) je Art.
     *
     * @param  list<array{scope: string, group: string, types: list<array{key: string, label: string, description: string|null, icon: string, bell: bool, mail: bool, mail_required: bool, mail_available: bool}>}>  $groups
     * @return array{enabled: bool, bell: bool, mail: bool, types: array<string, array{bell: bool, mail: bool}>}
     */
    public static function form(?Model $user, array $groups): array
    {
        $settings = self::of($user);
        $types = [];
        foreach ($groups as $group) {
            foreach ($group['types'] as $type) {
                $chosen = $settings['types'][$type['key']] ?? [];
                $types[$type['key']] = ['bell' => $chosen['bell'] ?? $type['bell'], 'mail' => $type['mail_required'] || ($type['mail_available'] && ($chosen['mail'] ?? $type['mail']))];
            }
        }

        return ['enabled' => $settings['enabled'], 'bell' => $settings['bell'], 'mail' => $settings['mail'], 'types' => $types];
    }

    /**
     * Speichern: nur bekannte Arten, nur Wahrheitswerte. Was die Person nicht sehen kann, bleibt, wie es war.
     *
     * @param  array{enabled: bool, bell: bool, mail: bool, types: array<string, array{bell?: bool, mail?: bool}>}  $values
     * @param  list<string>  $allowedKeys
     */
    public static function save(Model $user, array $values, array $allowedKeys): void
    {
        $current = self::of($user);
        $types = $current['types'];
        foreach ($values['types'] as $key => $channels) {
            if (in_array($key, $allowedKeys, true)) {
                $types[$key] = array_filter(['bell' => $channels['bell'] ?? null, 'mail' => $channels['mail'] ?? null], 'is_bool');
            }
        }
        $prefs = $user->getAttribute('preferences');
        $prefs = is_array($prefs) ? $prefs : [];
        $prefs['notifications'] = ['enabled' => $values['enabled'], 'bell' => $values['bell'], 'mail' => $values['mail'], 'types' => $types];
        $user->forceFill(['preferences' => $prefs])->save();
    }
}
