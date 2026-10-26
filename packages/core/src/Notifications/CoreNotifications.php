<?php

declare(strict_types=1);

namespace Basalto\Core\Notifications;

use Basalto\Core\Access\Level;
use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Events\VersionTransitioned;
use Basalto\Core\Extranet\Extranet;
use Basalto\Core\Saas\Events\ExtraDomainRequested;
use Basalto\Core\Saas\Saas;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Throwable;

/**
 * Die Benachrichtigungen des Kerns (TB 27): Arten im Verzeichnis und die Ereignisse, die sie auslösen. Module melden
 * ihre eigenen in ihrem Service-Provider an (Marktplatz, Lizenzverkauf, Kommentare, Mitglieder).
 */
final class CoreNotifications
{
    public const CONTENT = 'Inhalte und Freigabe';

    public const FORMS = 'Formulare';

    public const ACCOUNT = 'Konto und Sicherheit';

    public const OPERATIONS = 'Betrieb';

    public const BILLING = 'Abo und Rechnungen';

    public const EXTRANET = 'Kunden und SaaS';

    public static function register(NotificationTypes $types): void
    {
        $saas = static fn (): bool => Saas::enabled();
        $types->type('content.submitted', 'Ein Inhalt wartet auf Freigabe', self::CONTENT, 'Jemand reicht eine Fassung zur Freigabe ein.', area: 'approval', level: Level::Edit,
            mail: true, icon: 'review')
            ->type('content.rejected', 'Mein Inhalt wurde zurückgewiesen', self::CONTENT, 'Mit dem Kommentar der Freigabe.', mail: true, icon: 'cross')
            ->type('content.approved', 'Mein Inhalt wurde freigegeben', self::CONTENT, icon: 'check')
            ->type('content.published', 'Mein Inhalt ist veröffentlicht', self::CONTENT, 'Auch wenn die Veröffentlichung geplant war.', icon: 'feed')
            ->type('forms.submitted', 'Neue Einsendung in einem Formular', self::FORMS, 'Mehrere Einsendungen in einem Formular stehen in einer Zeile. Die Mail mit dem Inhalt geht an die Empfänger, die am Formular eingetragen sind.',
                area: 'forms', icon: 'forms', mailAvailable: false)
            // CR-011 TB 61: Anliegen zum Datenschutz aus dem Kundenkonto — Antwort innert eines Monats
            ->type('privacy.requests', 'Anliegen zum Datenschutz', self::ACCOUNT, 'Berichtigung, Einschränkung, Widerspruch oder ein anderes Anliegen aus dem Kundenkonto — Antwort innert eines Monats.',
                area: 'privacy', mail: true, icon: 'user')
            ->type('security.access', 'Änderungen an Anmeldung und Zugriff', self::ACCOUNT, 'Passkey hinzugefügt oder entfernt, API-Token angelegt.',
                mail: true, icon: 'passkeys')
            ->type('support.access', 'Der Support des Herstellers greift zu', self::ACCOUNT, 'Lesender Zugriff auf eine Ihrer Sites, mit Grund und Dauer.', area: 'settings',
                mail: true, icon: 'user')
            ->type('operations.updates', 'Updates verfügbar', self::OPERATIONS, 'Neue Versionen von Kern, Plugins oder Themes — einmal je neuem Stand.', area: 'updates', mail: true,
                icon: 'updates', when: static fn (): bool => ! Saas::enabled())
            ->type('operations.backup_failed', 'Eine Sicherung ist gescheitert', self::OPERATIONS, 'Die tägliche Sicherung oder «Backup jetzt».', area: 'operations', mail: true,
                icon: 'operations', when: static fn (): bool => ! Saas::enabled())
            ->type('account.billing', 'Rechnungen und Zahlungserinnerungen', self::BILLING, 'Die Mail mit der Rechnung kommt immer.', mailRequired: true, icon: 'license', when: $saas)
            ->type('account.trial', 'Testphase endet', self::BILLING, mailRequired: true, icon: 'license', when: $saas)
            ->type('account.lifecycle', 'Kündigung, Sperre, Löschung und Umzug', self::BILLING, 'Die Mail dazu kommt immer.', mailRequired: true, icon: 'license', when: $saas)
            ->type('account.domains', 'Eigene Domain ist live', self::BILLING, mailRequired: true, icon: 'sites', when: $saas)
            ->type('account.exports', 'Export ist bereit', self::BILLING, 'Mit Link zum Herunterladen.', mailRequired: true, icon: 'folder', when: $saas)
            // Extranet: das Team des Herstellers
            ->type('extranet.domains', 'Zusatzdomain angefragt', self::EXTRANET, 'Ein Konto bestellt eine weitere Domain — sie wartet auf die Einrichtung.', area: 'extranet_saas',
                scope: NotificationType::EXTRANET, mail: true, icon: 'sites')
            ->type('extranet.cancellations', 'Ein Konto kündigt', self::EXTRANET, area: 'extranet_customers', scope: NotificationType::EXTRANET, mail: true, icon: 'users');

        Event::listen(VersionTransitioned::class, [self::class, 'workflow']);
        Event::listen(PasskeyRegistered::class, static fn (PasskeyRegistered $e) => self::security($e->user, 'Passkey «:name» hinzugefügt', ['name' => (string) $e->passkey->getAttribute('name')]));
        Event::listen(PasskeyDeleted::class, static fn (PasskeyDeleted $e) => self::security($e->user, 'Passkey «:name» entfernt', ['name' => (string) $e->passkey->getAttribute('name')]));
        Event::listen(ExtraDomainRequested::class, static fn (ExtraDomainRequested $e) => Extranet::enabled() ? Notify::make('extranet.domains')
            ->title('Zusatzdomain :host angefragt', ['host' => $e->host])->body('Konto «:account», Site «:site».', ['account' => $e->account->name, 'site' => $e->site->name])
            ->url(route('extranet.saas.index'), 'SaaS-Betrieb öffnen')->toExtranet()->send() : 0);
    }

    /** Freigabe-Workflow (M-08): einreichen → Freigebende; zurückweisen, freigeben, veröffentlichen → wer eingereicht hat. */
    public static function workflow(VersionTransitioned $event): void
    {
        $version = $event->version;
        $entry = $version->entry;
        $site = $entry->site;
        $actorUser = auth()->user();
        $actor = $actorUser === null ? null : (int) $actorUser->getAuthIdentifier();
        $title = trim((string) $version->title) !== '' ? (string) $version->title : '#'.$entry->id;
        $params = ['title' => $title, 'locale' => strtoupper($version->locale)];
        $edit = route('basalto.entries.edit', ['entry' => $entry->id, 'locale' => $version->locale]);
        $owner = $version->submitted_by ?? $version->author_id;
        $model = config('auth.providers.users.model');
        $ownerModel = $owner !== null && $owner !== $actor && is_string($model) && is_subclass_of($model, Model::class) ? $model::query()->find($owner) : null;
        try {
            match (true) {
                $event->to === EntryStatus::InReview => Notify::make('content.submitted')->title('«:title» (:locale) wartet auf Freigabe', $params)
                    ->url(route('basalto.entries.review', ['entry' => $entry->id, 'locale' => $version->locale]), 'Zur Freigabe')->toArea($site)->except($actorUser)->send(),
                $event->from === EntryStatus::InReview && $event->to === EntryStatus::Draft && $event->comment !== null => Notify::make('content.rejected')
                    ->title('«:title» (:locale) wurde zurückgewiesen', $params)->body('Kommentar: :comment', ['comment' => $event->comment])
                    ->url($edit, 'Inhalt öffnen')->site($site)->to($ownerModel)->send(),
                $event->to === EntryStatus::Approved => Notify::make('content.approved')->title('«:title» (:locale) ist freigegeben', $params)
                    ->url($edit, 'Inhalt öffnen')->site($site)->to($ownerModel)->send(),
                $event->to === EntryStatus::Published => Notify::make('content.published')->title('«:title» (:locale) ist veröffentlicht', $params)
                    ->url($edit, 'Inhalt öffnen')->site($site)->to($ownerModel)->send(),
                default => 0,
            };
        } catch (Throwable $e) {
            // Eine Benachrichtigung darf den Workflow nie aufhalten
            report($e);
        }
    }

    /**
     * Sicherheitsrelevante Änderung am eigenen Konto — an die Person selbst (war sie es nicht, sieht sie es so).
     *
     * @param  array<string, string|int>  $params
     */
    public static function security(?Authenticatable $user, string $title, array $params = []): void
    {
        if (! $user instanceof Model) {
            return;
        }
        try {
            Notify::make('security.access')->title($title, $params)->body('War das nicht Sie? Prüfen Sie Passkeys, Sitzungen und API-Tokens im Profil.')
                ->url(route('basalto.profile', ['reiter' => 'sitzungen']), 'Profil öffnen')->to($user)->send();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
