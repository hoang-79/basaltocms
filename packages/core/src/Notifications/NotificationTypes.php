<?php

declare(strict_types=1);

namespace Basalto\Core\Notifications;

use Basalto\Core\Access\Access;
use Basalto\Core\Access\Level;
use Basalto\Core\Extranet\Extranet;
use Basalto\Core\Extranet\ExtranetAccess;
use Basalto\Core\Models\Site;
use Basalto\Core\Support\Text;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;

/**
 * Verzeichnis der Benachrichtigungen (TB 27): Kern und Module melden in `boot()` an, was sie verschicken — die
 * Einstellungen im Profil zeigen daraus die Schalter, der Versand fragt hier nach Vorgabe und Pflicht.
 *
 *     app(NotificationTypes::class)->type('shop.order', 'Neue Bestellung', 'Shop', area: 'shop', mail: true);
 *
 * Ohne Bereich ist eine Art persönlich (betrifft das eigene Konto oder die eigenen Inhalte) und für alle sichtbar.
 */
final class NotificationTypes
{
    /** @var array<string, NotificationType> */
    private array $types = [];

    /**
     * Eine Art anmelden. `$label`, `$group` und `$description` sind deutsche Texte (Schlüssel der Übersetzung);
     * `$scope` ist `backend` oder `extranet`, `$bell` und `$mail` die Vorgabe, solange niemand etwas einstellt.
     */
    public function type(
        string $key,
        string $label,
        string $group,
        ?string $description = null,
        ?string $area = null,
        Level $level = Level::View,
        string $scope = NotificationType::BACKEND,
        bool $bell = true,
        bool $mail = false,
        bool $mailRequired = false,
        string $icon = 'module',
        bool $mailAvailable = true,
        ?Closure $when = null,
    ): self {
        if (preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $key) !== 1) {
            throw new InvalidArgumentException("Schlüssel «{$key}»: Kleinbuchstaben mit Punkt, z. B. «shop.order»");
        }
        if (! in_array($scope, [NotificationType::BACKEND, NotificationType::EXTRANET, NotificationType::FRONTEND], true)) {
            throw new InvalidArgumentException("Schicht «{$scope}»: backend, extranet oder frontend");
        }
        $this->types[$key] = new NotificationType($key, $label, $group, $description, $area, $level, $scope, $bell, $mailAvailable && ($mail || $mailRequired), $mailRequired, $icon, $mailAvailable, $when);

        return $this;
    }

    public function get(string $key): ?NotificationType
    {
        return $this->types[$key] ?? null;
    }

    public function require(string $key): NotificationType
    {
        return $this->types[$key] ?? throw new InvalidArgumentException("Benachrichtigung «{$key}» ist nicht angemeldet");
    }

    /** @return array<string, NotificationType> */
    public function all(): array
    {
        return $this->types;
    }

    /**
     * Was eine Person bekommen kann: persönliche Arten immer, Arten mit Bereich nur, wenn sie ihn auf einer ihrer
     * Sites (Backend) bzw. im Team des Herstellers (Extranet) mindestens sieht.
     *
     * @return list<NotificationType>
     */
    public function forUser(Authenticatable $user): array
    {
        $sites = null;
        $visible = [];
        foreach ($this->types as $type) {
            // CR-006 (TB 38): Arten für Kundenkonten stellt man im Profil der Community ein, nicht im Backend
            if ($type->scope === NotificationType::FRONTEND || ($type->when !== null && ($type->when)() !== true)) {
                continue;
            }
            if ($type->scope === NotificationType::EXTRANET) {
                if (Extranet::enabled() && ($type->area === null ? app(ExtranetAccess::class)->isMember($user) : app(ExtranetAccess::class)->allows($user, $type->area, $type->level))) {
                    $visible[] = $type;
                }

                continue;
            }
            if ($type->area === null) {
                $visible[] = $type;

                continue;
            }
            $sites ??= Site::query()->forUser($user)->get();
            if ($sites->contains(static fn (Site $site): bool => app(Access::class)->allows($user, $site, (string) $type->area, $type->level))) {
                $visible[] = $type;
            }
        }

        return $visible;
    }

    /**
     * Für die Einstellungen: Gruppen in der Reihenfolge der Anmeldung, Texte in der Sprache der Oberfläche.
     *
     * @return list<array{scope: string, group: string, types: list<array{key: string, label: string, description: string|null, icon: string, bell: bool, mail: bool, mail_required: bool, mail_available: bool}>}>
     */
    public function groupedFor(Authenticatable $user): array
    {
        $groups = [];
        foreach ($this->forUser($user) as $type) {
            $id = $type->scope.'|'.$type->group;
            $groups[$id] ??= ['scope' => $type->scope, 'group' => Text::t($type->group), 'types' => []];
            $groups[$id]['types'][] = ['key' => $type->key, 'label' => Text::t($type->label), 'description' => $type->description === null ? null : Text::t($type->description),
                'icon' => $type->icon, 'bell' => $type->bell, 'mail' => $type->mail, 'mail_required' => $type->mailRequired, 'mail_available' => $type->mailAvailable];
        }
        // Backend vor Extranet, sonst Reihenfolge der Anmeldung
        $list = array_values($groups);
        usort($list, static fn (array $a, array $b): int => ($a['scope'] === NotificationType::EXTRANET) <=> ($b['scope'] === NotificationType::EXTRANET));

        return $list;
    }
}
