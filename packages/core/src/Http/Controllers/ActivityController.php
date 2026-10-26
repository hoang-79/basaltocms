<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Backend\Dates;
use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Models\Activity;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\Media;
use Basalto\Core\Settings\Catalog;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Support\Preferences;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Protokoll (K-15, Mockup 18/4): wer, wann, was, alt → neu — filterbar nach Art, Person, Zeitraum, Suche und Objekt
 * (je Inhalt), als CSV für Excel (UTF-8 mit BOM, Semikolon). Aufbewahrung als Einstellung, Löschlauf nachts.
 */
final class ActivityController
{
    /** @return array<string, string> Art → Beschriftung in der Sprache der Oberfläche */
    private static function kinds(): array
    {
        return ['content' => __('Inhalte'), 'settings' => __('Einstellungen'), 'users' => __('Benutzer'), 'logins' => __('Anmeldungen'), 'system' => __('System')];
    }

    /** @return array<string, string> Aktion → Beschriftung in der Sprache der Oberfläche */
    private static function labels(): array
    {
        return [
            'entry.saved' => __('Fassung gespeichert'), 'version.submitted' => __('Fassung eingereicht'), 'version.withdrawn' => __('Fassung zurückgezogen'),
            'version.rejected' => __('Fassung zurückgewiesen'), 'version.approved' => __('Fassung freigegeben'), 'version.scheduled' => __('Veröffentlichung geplant'),
            'version.unscheduled' => __('Planung aufgehoben'), 'version.published' => __('Fassung veröffentlicht'), 'version.unpublished' => __('Offline genommen'),
            'version.publish_failed' => __('Geplante Veröffentlichung gescheitert'), 'media.uploaded' => __('Datei hochgeladen'), 'media.trashed' => __('Datei in den Papierkorb'),
            'media.restored' => __('Datei wiederhergestellt'), 'setting.changed' => __('Einstellung geändert'), 'site.created' => __('Site angelegt'), 'site.updated' => __('Site geändert'),
            'user.logged_in' => __('Anmeldung'), 'user.role_changed' => __('Rolle geändert'), 'user.invited' => __('Benutzer eingeladen'), 'user.invitation_resent' => __('Einladung erneut gesendet'),
            'user.invitation_revoked' => __('Einladung zurückgezogen'), 'user.invitation_accepted' => __('Einladung angenommen'), 'user.deactivated' => __('Konto deaktiviert'),
            'user.activated' => __('Konto aktiviert'), 'user.sessions_ended' => __('Sitzungen beendet'), 'user.profile_updated' => __('Profil geändert'), 'system.cleanup' => __('Löschlauf'),
            // TB 12 Scheibe 1: Konto-Rollen im Konto-Bereich der SaaS
            'account.role' => __('Konto-Rolle geändert'),
            // TB 13 Scheibe 4 (M-25): SSO für Backend-Benutzer
            'sso.updated' => __('SSO eingerichtet'), 'sso.removed' => __('SSO entfernt'), 'sso.linked' => __('SSO verknüpft'), 'sso.refused' => __('SSO abgewiesen'), 'sso.user_created' => __('Benutzer über SSO angelegt'), 'sso.role_granted' => __('Rolle über SSO vergeben'),
            // TB 53: Anmeldung über Drittanbieter für Kundenkonten
            'social.updated' => __('Anmeldung für Kundenkonten eingerichtet'), 'social.removed' => __('Anmeldung für Kundenkonten entfernt'),
            // TB 12 Scheibe 3: Domains der SaaS
            'site.alias_changed' => __('Alias geändert'), 'site.domain_added' => __('Domain hinzugefügt'), 'site.domain_verified' => __('Domain verbunden'), 'site.domain_primary' => __('Hauptadresse geändert'), 'site.domain_removed' => __('Domain entfernt'), 'account.extra_domain_ordered' => __('Zusatzdomain bestellt'),
            // TB 12 Scheibe 4: Export, Kündigung, Löschung, Umzug zwischen Instanzen
            'account.export_requested' => __('Export angefordert'), 'account.cancelled' => __('Konto gekündigt'), 'account.cancellation_revoked' => __('Kündigung zurückgenommen'),
            'account.erased' => __('Konto gelöscht'), 'account.move_started' => __('Umzug gestartet'), 'account.moved' => __('Konto umgezogen'), 'account.move_received' => __('Umzug angekommen'),
            'account.move_confirmed' => __('Umzug abgeschlossen'), 'account.move_cleaned' => __('Alte Kopie nach dem Umzug gelöscht'),
            // TB 15 Scheibe 4 (CR-004 P-06): Lizenz und Kundenkonto beim Hersteller
            'license.stored' => __('Lizenzschlüssel eingetragen'), 'license.removed' => __('Lizenzschlüssel entfernt'),
            'license.vendor_connected' => __('Mit dem Kundenkonto beim Hersteller verbunden'), 'license.vendor_disconnected' => __('Vom Kundenkonto beim Hersteller getrennt'),
        ];
    }

    public function __construct(private readonly CurrentSite $current, private readonly Dates $dates, private readonly Settings $settings) {}

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $base = $this->query($filters, withKind: false);
        $kinds = [];
        foreach (self::kinds() as $value => $label) {
            $kinds[] = ['value' => $value, 'label' => $label, 'count' => self::applyKind(clone $base, $value)->count()];
        }
        $query = $this->query($filters);
        $total = (clone $query)->count();
        $page = $query->orderByDesc('id')->simplePaginate(Preferences::perPage($request->user()))->appends(array_filter($filters, static fn (string|int $v): bool => $v !== ''));
        $names = $this->names($page->getCollection()->pluck('user_id'));
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');
        $people = Activity::query()->where(fn (Builder $q) => $this->scope($q))->whereNotNull('user_id')->distinct()->pluck('user_id');

        return Inertia::render('core::Activity/Index', [
            'entries' => $page->getCollection()->map(fn (Activity $a): array => $this->row($a, $names))->values()->all(),
            'filters' => $filters,
            'kinds' => $kinds,
            'people' => $userModel::query()->whereIn('id', $people)->orderBy('name')->get(['id', 'name'])->map(static fn (Model $u): array => ['id' => (int) $u->getKey(), 'name' => (string) $u->getAttribute('name')])->all(),
            'pagination' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'total' => $total,
            'retention_days' => (int) $this->settings->get('activity.retention_days', 365),
            'urls' => ['index' => route('basalto.activity.index'), 'export' => route('basalto.activity.export', array_filter($filters, static fn (string|int $v): bool => $v !== ''))],
        ]);
    }

    /** CSV für Excel: UTF-8 mit BOM, Semikolon, eine Zeile je Eintrag, dieselben Filter wie die Liste. */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $query = $this->query($filters)->orderByDesc('id');

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fwrite($out, "\u{FEFF}");
            fputcsv($out, [__('Zeit'), __('Wer'), __('Was'), __('Objekt'), __('Änderung'), __('Details')], ';', '"', '');
            $query->chunk(500, function ($chunk) use ($out): void {
                $names = $this->names($chunk->pluck('user_id'));
                foreach ($chunk as $a) {
                    $row = $this->row($a, $names);
                    fputcsv($out, [$a->created_at->timezone($this->dates->timezone())->format('d.m.Y H:i:s'), $row['user'] ?? __('System'), $row['action_label'], $row['subject'] ?? '',
                        $row['change'] ?? '', trim(self::flat($a->before).' → '.self::flat($a->after), ' →')], ';', '"', '');
                }
            });
            fclose($out);
        }, sprintf('protokoll-%s.csv', now()->format('Y-m-d')), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{kind: string, user: string, days: int, q: string, subject: string} */
    private function filters(Request $request): array
    {
        $days = (int) $request->query('days', 30);

        return [
            'kind' => array_key_exists((string) $request->query('kind'), self::kinds()) ? (string) $request->query('kind') : '',
            'user' => is_numeric($request->query('user')) ? (string) $request->query('user') : '',
            'days' => in_array($days, [1, 7, 30, 90, 365, 3650], true) ? $days : 30,
            'q' => trim((string) $request->query('q', '')),
            'subject' => preg_match('/^(entry|media):\d+$/', (string) $request->query('subject')) === 1 ? (string) $request->query('subject') : '',
        ];
    }

    /**
     * @param  array{kind: string, user: string, days: int, q: string, subject: string}  $filters
     * @return Builder<Activity>
     */
    private function query(array $filters, bool $withKind = true): Builder
    {
        $query = Activity::query()->where(fn (Builder $q) => $this->scope($q))->where('created_at', '>=', now()->subDays($filters['days']));
        if ($filters['user'] !== '') {
            $query->where('user_id', (int) $filters['user']);
        }
        if ($filters['subject'] !== '') {
            [$type, $id] = explode(':', $filters['subject']);
            $query->where('subject_type', $type === 'entry' ? Entry::class : Media::class)->where('subject_id', (int) $id);
        }
        if ($filters['q'] !== '') {
            $like = '%'.$filters['q'].'%';
            // Auch die Bezeichnungen in der Sprache der Oberfläche («Einladung» findet «user.invited» …)
            $needle = mb_strtolower($filters['q']);
            $actions = array_keys(array_filter(self::labels(), static fn (string $label): bool => str_contains(mb_strtolower($label), $needle)));
            $query->where(static fn (Builder $q) => $q->where('action', 'like', $like)->orWhere('after', 'like', $like)->orWhere('before', 'like', $like)->orWhereIn('action', $actions));
        }

        return $withKind && $filters['kind'] !== '' ? self::applyKind($query, $filters['kind']) : $query;
    }

    /**
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    private static function applyKind(Builder $query, string $kind): Builder
    {
        return match ($kind) {
            'content' => $query->where(static fn (Builder $q) => $q->where('action', 'like', 'entry.%')->orWhere('action', 'like', 'version.%')->orWhere('action', 'like', 'media.%')),
            'settings' => $query->where(static fn (Builder $q) => $q->where('action', 'like', 'setting.%')->orWhere('action', 'like', 'site.%')),
            'users' => $query->where('action', 'like', 'user.%')->where('action', '!=', 'user.logged_in'),
            'logins' => $query->where('action', 'user.logged_in'),
            default => $query->where('action', 'like', 'system.%'),
        };
    }

    /** @param  Builder<Activity>  $q */
    private function scope(Builder $q): void
    {
        $site = $this->current->get();
        $q->whereNull('site_id')->when($site !== null, static fn (Builder $q) => $q->orWhere('site_id', $site?->id));
    }

    /**
     * @param  array<int, string>  $names
     * @return array{id: int, at: string|null, user: string|null, action: string, action_label: string, subject: string|null, subject_url: string|null, change: string|null}
     */
    private function row(Activity $a, array $names): array
    {
        $subject = null;
        $url = null;
        if ($a->subject_type === Entry::class && $a->subject_id !== null) {
            $title = is_string($a->after['title'] ?? null) ? $a->after['title'] : EntryVersion::query()->where('entry_id', $a->subject_id)->orderByDesc('id')->value('title');
            $locale = is_string($a->after['locale'] ?? null) ? $a->after['locale'] : null;
            $number = $a->after['number'] ?? null;
            $subject = trim(sprintf('%s%s%s', $title ?? (string) __('Inhalt #:id', ['id' => $a->subject_id]), is_numeric($number) ? ' · '.(string) __('Nr. :number', ['number' => $number]) : '', $locale !== null ? ' ('.strtoupper($locale).')' : ''));
            $url = route('basalto.activity.index', ['subject' => 'entry:'.$a->subject_id, 'days' => 3650]);
        } elseif ($a->subject_type === Media::class && $a->subject_id !== null) {
            $subject = is_string($a->after['name'] ?? null) ? $a->after['name'] : (string) __('Datei #:id', ['id' => $a->subject_id]);
            $url = route('basalto.media.index', ['media' => $a->subject_id]);
        } elseif (is_string($a->after['key'] ?? null)) {
            $subject = Catalog::find($a->after['key'])['label'] ?? $a->after['key'];
        } elseif ($a->subject_type === config('auth.providers.users.model') && $a->subject_id !== null) {
            $subject = is_string($a->after['email'] ?? null) ? $a->after['email'] : ($names[$a->subject_id] ?? $this->names(collect([$a->subject_id]))[$a->subject_id] ?? (string) __('Benutzer gelöscht'));
        } elseif ($a->subject_type !== null) {
            $subject = is_string($a->after['email'] ?? null) ? $a->after['email'] : sprintf('%s #%d', class_basename($a->subject_type), $a->subject_id);
        } elseif (is_string($a->after['email'] ?? $a->before['email'] ?? null)) {
            $subject = (string) ($a->after['email'] ?? $a->before['email']);
        }

        return ['id' => $a->id, 'at' => $this->dates->relative($a->created_at), 'user' => $a->user_id === null ? null : ($names[$a->user_id] ?? (string) __('Benutzer gelöscht')),
            'action' => $a->action, 'action_label' => self::labels()[$a->action] ?? $a->action, 'subject' => $subject, 'subject_url' => $url, 'change' => self::change($a)];
    }

    private static function change(Activity $a): ?string
    {
        $before = is_array($a->before) ? $a->before : [];
        $after = is_array($a->after) ? $a->after : [];
        if (isset($after['status']) || isset($before['status'])) {
            return sprintf('%s → %s', self::status($before['status'] ?? null), self::status($after['status'] ?? null)).(isset($after['comment']) ? ' · «'.$after['comment'].'»' : '');
        }
        foreach (['value', 'role', 'active'] as $key) {
            if (array_key_exists($key, $before) || array_key_exists($key, $after)) {
                return sprintf('%s → %s', self::scalar($before[$key] ?? null), self::scalar($after[$key] ?? null));
            }
        }

        return null;
    }

    private static function status(mixed $value): string
    {
        $status = is_string($value) ? EntryStatus::tryFrom($value) : null;

        return $status?->label() ?? '—';
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? __('ein') : __('aus'),
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }

    /** @param  array<string, mixed>|null  $value */
    private static function flat(?array $value): string
    {
        return $value === null ? '' : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  Collection<int, mixed>  $ids
     * @return array<int, string>
     */
    private function names(Collection $ids): array
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');
        $out = [];
        foreach ($userModel::query()->whereIn('id', $ids->filter()->unique()->values())->get(['id', 'name']) as $u) {
            $out[(int) $u->getKey()] = (string) $u->getAttribute('name');
        }

        return $out;
    }
}
