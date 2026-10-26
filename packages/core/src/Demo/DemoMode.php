<?php

declare(strict_types=1);

namespace Basalto\Core\Demo;

use Basalto\Core\Accounts\AccountRole;
use Basalto\Core\Sites\SiteRole;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Die öffentliche Demo (TB 11 Scheibe 4, G-10; Fristo-Muster): nur mit `BASALTO_DEMO=true`. Dann gibt es auf der
 * Anmeldeseite einen Schnellzugang in drei Rollen ohne Passwort und Code, jede Mail wird abgefangen statt zugestellt,
 * das Backend zeigt ein Hinweisband, und der Scheduler setzt die Datenbank jede Nacht mit `basalto:demo-reset` neu auf.
 * Ohne den Schalter ist nichts davon erreichbar.
 *
 * @phpstan-type DemoAccount array{role: string, email: string, name: string, site_role: SiteRole, account_role: AccountRole|null}
 */
final class DemoMode
{
    /** Die drei Konten des Schnellzugangs — Schlüssel ist der Pfad «/admin/login/demo/{rolle}». */
    public const ROLES = ['betreiber', 'redaktion', 'uebersetzung'];

    public static function enabled(): bool
    {
        return (bool) config('basalto.demo.enabled', false);
    }

    /**
     * @return array<string, DemoAccount>
     */
    public static function accounts(): array
    {
        return [
            'betreiber' => ['role' => 'betreiber', 'email' => 'betreiber@demo.basaltocms.com', 'name' => 'Betreiberin Demo', 'site_role' => SiteRole::Owner, 'account_role' => AccountRole::Owner],
            'redaktion' => ['role' => 'redaktion', 'email' => 'redaktion@demo.basaltocms.com', 'name' => 'Redaktion Demo', 'site_role' => SiteRole::Editor, 'account_role' => null],
            'uebersetzung' => ['role' => 'uebersetzung', 'email' => 'uebersetzung@demo.basaltocms.com', 'name' => 'Übersetzung Demo', 'site_role' => SiteRole::Translator, 'account_role' => null],
        ];
    }

    /**
     * Der Schnellzugang für die Anmeldeseite — null ohne Demo-Modus.
     *
     * @return list<array{role: string, label: string, hint: string, url: string}>|null
     */
    public static function quickAccess(): ?array
    {
        if (! self::enabled()) {
            return null;
        }
        $labels = [
            'betreiber' => [__('Als Betreiber ansehen'), __('Alles: Einrichtung, Themes, Benutzer, Einstellungen')],
            'redaktion' => [__('Als Redaktion ansehen'), __('Seiten, Blog und Medien bearbeiten')],
            'uebersetzung' => [__('Als Übersetzung ansehen'), __('Inhalte aus der Quellfassung übersetzen')],
        ];

        return array_map(static fn (string $role): array => ['role' => $role, 'label' => $labels[$role][0], 'hint' => $labels[$role][1], 'url' => route('basalto.login.demo', ['role' => $role])], self::ROLES);
    }

    /** Das Hinweisband im Backend — null ohne Demo-Modus. */
    public static function notice(): ?string
    {
        if (! self::enabled()) {
            return null;
        }
        $text = __('Demo — Daten werden jede Nacht zurückgesetzt, Mails werden nicht versendet.');

        return is_string($text) ? $text : null;
    }

    /**
     * Mails abfangen: Im Demo-Modus ist der Mailer «log» (Vorgabe, `BASALTO_DEMO_MAILER`), und jede Mail wird vor dem
     * Versand angehalten — auch eine, die ausdrücklich einen anderen Mailer wählt. Geprüft wird bei jeder Mail, nicht
     * beim Start: Der Schalter wirkt sofort.
     */
    public static function boot(): void
    {
        if (self::enabled()) {
            config()->set('mail.default', (string) config('basalto.demo.mailer', 'log'));
        }
        Event::listen(MessageSending::class, static function (MessageSending $event): ?bool {
            if (! self::enabled()) {
                return null;
            }
            $to = array_map(static fn ($address): string => $address->getAddress(), $event->message->getTo());
            Log::info('Demo: Mail abgefangen, nicht versendet', ['to' => $to, 'subject' => $event->message->getSubject()]);

            return false;
        });
    }
}
