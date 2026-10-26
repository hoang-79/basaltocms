<?php

declare(strict_types=1);

namespace Basalto\Core\Transfer;

use Basalto\Core\Access\Access;
use Basalto\Core\Activity\Recorder;
use Basalto\Core\Cache\PageCache;
use Basalto\Core\Content\Pages;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\Site;
use Basalto\Core\Search\SearchIndexer;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Theme\ThemeLibrary;
use Basalto\Core\Theme\ThemeLoader;
use Basalto\Lava\Theme\LavaSource;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Import einer exportierten Site (M-18) in eine Installation, in der ihr Handle noch frei ist. Erst wird das Archiv
 * geprüft (ZIP-Slip, Manifest, SHA-256 je Datei, Formatversion), dann läuft alles in **einer** Transaktion: neue
 * Kennungen, Bezüge über UUID und Schlüssel, Marken in JSON-Spalten aufgelöst. Dateien werden in der Transaktion
 * geschrieben und bei jedem Fehler wieder gelöscht — es bleibt entweder die ganze Site oder nichts.
 *
 * Benutzer: Wer mit derselben E-Mail schon ein Konto hat, wird Mitglied mit seiner Rolle; für alle anderen entsteht
 * eine Einladung **ohne Mail** — ein Konto braucht die Zustimmung der Person, und ein Import soll niemanden ungefragt
 * anschreiben. Die Verwaltung schickt sie unter «Benutzer» ab («erneut senden» erzeugt den Link).
 *
 * @phpstan-type Result array{site: Site, counts: array<string, int>, notes: list<string>}
 */
final class SiteImporter
{
    /** @var list<int> Formatversionen, die dieser Kern liest */
    public const SUPPORTED_VERSIONS = [1];

    public function __construct(
        private readonly SearchIndexer $search,
        private readonly PageCache $pageCache,
        private readonly Pages $pages,
        private readonly ThemeLoader $themes,
        private readonly CurrentSite $current,
        private readonly Settings $settings,
        private readonly Recorder $recorder,
    ) {}

    /**
     * @return Result
     *
     * @throws TransferException
     */
    public function import(string $zip, ?string $handle = null, ?Account $account = null): array
    {
        $reader = new ArchiveReader($zip);
        $manifest = $reader->verify();
        if (($manifest['format'] ?? null) !== SiteExporter::FORMAT || ! $reader->has(SiteExporter::DATA_FILE)) {
            throw new TransferException('Kein Site-Export von Basalto (format «'.SiteExporter::FORMAT.'» und site.json erwartet).');
        }
        $payload = json_decode($reader->get(SiteExporter::DATA_FILE), true);
        if (! is_array($payload) || ! is_array($payload['site'] ?? null)) {
            throw new TransferException('site.json ist kein gültiger Export.');
        }
        $version = $payload['format_version'] ?? null;
        if (! is_int($version) || ! in_array($version, self::SUPPORTED_VERSIONS, true)) {
            throw new TransferException(sprintf('Formatversion %s wird nicht gelesen (dieser Kern liest %s) — %s', var_export($version, true), implode(', ', self::SUPPORTED_VERSIONS),
                is_int($version) && $version > max(self::SUPPORTED_VERSIONS) ? 'Basalto aktualisieren.' : 'Archiv prüfen.'));
        }
        $handle ??= (string) $payload['site']['handle'];
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/', $handle) !== 1) {
            throw new TransferException(sprintf('Handle «%s» ist ungültig (Kleinbuchstaben, Ziffern, Bindestrich; höchstens 40 Zeichen).', $handle));
        }
        if (Site::query()->where('handle', $handle)->exists()) {
            throw new TransferException(sprintf('Site «%s» gibt es in dieser Installation schon — mit --handle= unter einem anderen Handle importieren.', $handle));
        }
        $notes = [];
        $counts = [];
        // in der Transaktion geschriebene Dateien — bei einem Fehler wieder weg
        $written = [];
        try {
            $site = DB::transaction(function () use ($reader, $payload, $handle, $account, &$notes, &$counts, &$written): Site {
                return $this->write($reader, $payload, $handle, $notes, $counts, $written, $account);
            });
        } catch (Throwable $e) {
            foreach ($written as $file) {
                $file['disk']->delete($file['path']);
            }

            throw $e;
        }

        $this->themes->forget();
        $this->pages->forget();
        $counts['search_documents'] = $this->current->within($site, fn (Site $s): int => $this->search->rebuild($s));
        $this->pageCache->invalidate($site->id);
        $this->recorder->record('site.imported', $site, null, ['handle' => $site->handle, 'counts' => $counts, 'basalto_version' => $payload['basalto_version'] ?? null], $site);

        return ['site' => $site, 'counts' => $counts, 'notes' => $notes];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $notes
     * @param  array<string, int>  $counts
     * @param  list<array{disk: Filesystem, path: string}>  $written
     * @param  ?Account  $account  TB 12 Scheibe 4: Konto der Site (Umzug zwischen Instanzen) — ohne Angabe das Konto der Installation
     */
    private function write(ArchiveReader $reader, array $payload, string $handle, array &$notes, array &$counts, array &$written, ?Account $account = null): Site
    {
        $now = CarbonImmutable::now()->format('Y-m-d H:i:s');
        $users = [];
        foreach (DB::table('users')->get(['id', 'email']) as $user) {
            $users[strtolower((string) $user->email)] = (int) $user->id;
        }
        $userId = static fn (mixed $email): ?int => is_string($email) ? ($users[strtolower($email)] ?? null) : null;
        $source = $payload['site'];

        $accountId = $account->id ?? Account::installation()->id;
        $siteId = (int) DB::table('sites')->insertGetId([
            'account_id' => $accountId, 'handle' => $handle, 'name' => (string) $source['name'], 'default_locale' => (string) $source['default_locale'],
            'timezone' => (string) ($source['timezone'] ?? 'Europe/Zurich'), 'theme' => $source['theme'] ?? null, 'settings' => null, 'live_at' => $source['live_at'] ?? null,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach (self::rows($payload, 'locales') as $row) {
            DB::table('site_locales')->insert(['site_id' => $siteId, 'locale' => (string) $row['locale'], 'is_default' => (bool) $row['is_default'], 'is_required' => (bool) $row['is_required'],
                'fallback_locale' => $row['fallback_locale'] ?? null, 'sort_order' => (int) $row['sort_order'], 'created_at' => $row['created_at'] ?? $now, 'updated_at' => $row['updated_at'] ?? $now]);
        }
        $counts['locales'] = count(self::rows($payload, 'locales'));

        $counts['domains'] = 0;
        $aliasDomain = (string) config('basalto.saas.alias_domain', 'basaltocms.com');
        foreach (self::rows($payload, 'domains') as $row) {
            $host = strtolower((string) $row['host']);
            if ($row['kind'] === 'alias' && $host === $source['handle'].'.'.$aliasDomain) {
                $host = $handle.'.'.$aliasDomain;
            }
            if (DB::table('site_domains')->where('host', $host)->exists()) {
                $notes[] = sprintf('Domain %s gehört in dieser Installation schon einer Site — nicht übernommen.', $host);

                continue;
            }
            DB::table('site_domains')->insert(['site_id' => $siteId, 'host' => $host, 'kind' => (string) $row['kind'], 'is_primary' => (bool) $row['is_primary'], 'created_at' => $row['created_at'] ?? $now, 'updated_at' => $row['updated_at'] ?? $now]);
            $counts['domains']++;
        }

        $counts['members'] = 0;
        $counts['invitations'] = 0;
        $hours = (int) $this->settings->get('auth.invitation_hours', 72);
        foreach (self::rows($payload, 'members') as $row) {
            // Vorlagen oder eigene Rollen, die es im Zielkonto gibt (CR-005)
            $role = (string) $row['role'];
            $email = strtolower((string) $row['email']);
            if (app(Access::class)->resolve($role, $accountId) === null) {
                $notes[] = sprintf('Rolle «%s» von %s ist unbekannt — nicht übernommen.', $row['role'], $email);

                continue;
            }
            $id = $userId($email);
            if ($id !== null && ! ($row['pending'] ?? false)) {
                DB::table('site_user')->insert(['site_id' => $siteId, 'user_id' => $id, 'role' => $role, 'created_at' => $now, 'updated_at' => $now]);
                $counts['members']++;

                continue;
            }
            DB::table('invitations')->insert(['site_id' => $siteId, 'email' => $email, 'role' => $role, 'token_hash' => hash('sha256', Str::random(64)), 'invited_by' => null,
                'expires_at' => CarbonImmutable::now()->addHours($hours)->format('Y-m-d H:i:s'), 'accepted_at' => null, 'created_at' => $now, 'updated_at' => $now]);
            $counts['invitations']++;
            $notes[] = sprintf('%s (%s): Einladung angelegt, nicht versendet — unter «Benutzer» erneut senden.', $email, $role);
        }

        foreach (self::rows($payload, 'settings') as $row) {
            DB::table('settings')->insert(['site_id' => $siteId, 'key' => (string) $row['key'], 'value' => self::encode($row['value'] ?? null), 'updated_by' => $userId($row['updated_by'] ?? null),
                'created_at' => $row['created_at'] ?? $now, 'updated_at' => $row['updated_at'] ?? $now]);
        }
        $counts['settings'] = count(self::rows($payload, 'settings'));
        // TB 22: Theme-Bibliothek — ältere Exporte kennen genau ein Datenbank-Theme (`scope = theme` ohne Schlüssel)
        $themeIds = [];
        $themeFiles = [];
        foreach (self::rows($payload, 'site_themes') as $row) {
            $name = (string) $row['name'];
            if (preg_match(ThemeLibrary::NAME, $name) !== 1) {
                throw new TransferException(sprintf('Unzulässiger Theme-Name «%s» abgelehnt.', $name));
            }
            $assets = is_array($row['assets'] ?? null) ? $row['assets'] : [];
            $id = (int) DB::table('site_themes')->insertGetId(['site_id' => $siteId, 'name' => $name, 'label' => $row['label'] ?? null, 'description' => $row['description'] ?? null,
                'version' => $row['version'] ?? null, 'origin' => (string) ($row['origin'] ?? 'ordner'), 'origin_name' => $row['origin_name'] ?? null, 'signed' => (bool) ($row['signed'] ?? false),
                'assets' => self::encode($assets === [] ? null : $assets), 'created_by' => $userId($row['created_by'] ?? null), 'created_at' => $row['created_at'] ?? $now, 'updated_at' => $row['updated_at'] ?? $now]);
            $themeIds[(string) $row['key']] = $id;
            foreach (array_keys($assets) as $file) {
                if (! ArchiveReader::isSafeName((string) $file) || ! ThemeLibrary::isBinaryPath((string) $file)) {
                    throw new TransferException(sprintf('Unsicherer Theme-Pfad «%s» abgelehnt.', $file));
                }
                $themeFiles['theme-assets/'.$siteId.'/'.$id.'/'.$file] = 'theme-assets/'.$row['key'].'/'.$file;
            }
        }
        $legacyTheme = function () use (&$themeIds, $siteId, $source, $now): int {
            if (! isset($themeIds[''])) {
                $name = (string) ($source['theme'] ?? config('basalto.default_theme', 'nguyen-development'));
                $themeIds[''] = (int) DB::table('site_themes')->insertGetId(['site_id' => $siteId, 'name' => $name, 'origin' => 'ordner', 'origin_name' => $name, 'created_at' => $now, 'updated_at' => $now]);
            }

            return $themeIds[''];
        };
        $themeOf = fn (array $row): int => isset($row['theme']) ? $this->known($themeIds, $row['theme'], 'Theme') : $legacyTheme();
        $hasDatabaseTheme = false;
        foreach (self::rows($payload, 'lava_sources') as $row) {
            $scope = (string) $row['scope'];
            if ($scope === LavaSource::SCOPE_THEME) {
                $hasDatabaseTheme = true;
                $scope = LavaSource::themeScope($themeOf($row));
            }
            DB::table('lava_sources')->insert(['site_id' => $siteId, 'scope' => $scope, 'path' => (string) $row['path'], 'source' => (string) $row['source'],
                'created_at' => $row['created_at'] ?? $now, 'updated_at' => $row['updated_at'] ?? $now]);
        }
        $counts['lava_sources'] = count(self::rows($payload, 'lava_sources'));
        // Entwürfe und Fassungen ohne Theme (zu einem Theme, das zurück in den Ordner ging) bleiben draussen
        $owner = fn (array $row): ?int => isset($row['theme']) ? $themeOf($row) : ($themeIds[''] ?? null);
        foreach (self::rows($payload, 'theme_drafts') as $row) {
            if ($owner($row) === null) {
                continue;
            }
            DB::table('theme_drafts')->insert(['site_id' => $siteId, 'site_theme_id' => $owner($row), 'path' => (string) $row['path'], 'source' => $row['source'] ?? null, 'base_hash' => $row['base_hash'] ?? null,
                'updated_by' => $userId($row['updated_by'] ?? null), 'submitted_at' => $row['submitted_at'] ?? null, 'submitted_by' => $userId($row['submitted_by'] ?? null),
                'created_at' => $row['created_at'] ?? $now, 'updated_at' => $row['updated_at'] ?? $now]);
        }
        foreach (self::rows($payload, 'theme_versions') as $row) {
            if ($owner($row) === null) {
                continue;
            }
            DB::table('theme_versions')->insert(['site_id' => $siteId, 'site_theme_id' => $owner($row), 'number' => (int) $row['number'], 'files' => (string) $row['files'], 'note' => $row['note'] ?? null,
                'submitted_by' => $userId($row['submitted_by'] ?? null), 'approved_by' => $userId($row['approved_by'] ?? null), 'published_at' => $row['published_at'] ?? null,
                'created_at' => $row['created_at'] ?? $now, 'updated_at' => $row['updated_at'] ?? $now]);
        }
        $counts['theme_versions'] = count(self::rows($payload, 'theme_versions'));
        $counts['site_themes'] = count($themeIds);
        $theme = $source['theme'] ?? null;
        if (is_string($theme) && ! $hasDatabaseTheme && ! in_array($theme, array_column(self::rows($payload, 'site_themes'), 'name'), true) && ! is_dir(rtrim((string) (config('basalto.themes_path') ?: base_path('themes')), '/').'/'.$theme)) {
            $notes[] = sprintf('Theme «%s» fehlt in dieser Installation — die Site zeigt erst nach dem Installieren des Themes etwas an.', $theme);
        }

        // Medien: Ordner in zwei Durchgängen (Eltern können später stehen), dann Medien mit neuem Pfad unter sites/<id>/
        $folders = [];
        foreach (self::rows($payload, 'media_folders') as $row) {
            $folders[(string) $row['key']] = (int) DB::table('media_folders')->insertGetId(['site_id' => $siteId, 'parent_id' => null, 'name' => (string) $row['name'],
                'position' => (int) $row['position'], 'created_at' => $row['created_at'] ?? $now, 'updated_at' => $row['updated_at'] ?? $now]);
        }
        foreach (self::rows($payload, 'media_folders') as $row) {
            if (($row['parent'] ?? null) !== null) {
                DB::table('media_folders')->where('id', $folders[(string) $row['key']])->update(['parent_id' => $this->known($folders, $row['parent'], 'Ordner')]);
            }
        }
        $counts['media_folders'] = count($folders);
        $diskName = (string) config('filesystems.default');
        $disk = Storage::disk($diskName);
        $prefix = 'sites/'.$siteId.'/';
        $media = [];
        $mediaFiles = $themeFiles;
        foreach (self::rows($payload, 'media') as $row) {
            $relative = (string) $row['path'];
            if (! ArchiveReader::isSafeName($relative)) {
                throw new TransferException(sprintf('Unsicherer Medienpfad «%s» abgelehnt.', $relative));
            }
            $variants = is_array($row['variants'] ?? null) ? SiteExporter::mapVariantPaths($row['variants'], function (string $file) use ($prefix, &$mediaFiles): string {
                if (! ArchiveReader::isSafeName($file)) {
                    throw new TransferException(sprintf('Unsicherer Variantenpfad «%s» abgelehnt.', $file));
                }
                $mediaFiles[$prefix.$file] = 'media/'.$file;

                return $prefix.$file;
            }) : null;
            if ($row['file'] ?? false) {
                $mediaFiles[$prefix.$relative] = 'media/'.$relative;
            }
            $media[(string) $row['key']] = (int) DB::table('media')->insertGetId(['site_id' => $siteId, 'folder_id' => isset($row['folder']) ? $this->known($folders, $row['folder'], 'Ordner') : null,
                'disk' => $diskName, 'path' => $prefix.$relative, 'name' => (string) $row['name'], 'title' => $row['title'] ?? null, 'credit' => $row['credit'] ?? null,
                'alt' => self::encode($row['alt'] ?? null), 'mime' => (string) $row['mime'], 'size' => (int) $row['size'], 'width' => $row['width'] ?? null, 'height' => $row['height'] ?? null,
                'focus_x' => (float) ($row['focus_x'] ?? 0.5), 'focus_y' => (float) ($row['focus_y'] ?? 0.5), 'variants' => self::encode($variants),
                'variants_generated_at' => $row['variants_generated_at'] ?? null, 'created_by' => $userId($row['created_by'] ?? null),
                'created_at' => $row['created_at'] ?? $now, 'updated_at' => $row['updated_at'] ?? $now, 'deleted_at' => $row['deleted_at'] ?? null]);
        }
        $counts['media'] = count($media);

        // Inhalte: UUIDs bleiben — ausser sie stehen schon in dieser Installation (Kopie einer Site), dann alle neu
        $uuids = [];
        foreach (self::rows($payload, 'entries') as $row) {
            $uuids[(string) $row['uuid']] = (string) $row['uuid'];
        }
        $taken = false;
        foreach (array_chunk(array_keys($uuids), 500) as $chunk) {
            $taken = $taken || DB::table('entries')->whereIn('uuid', $chunk)->exists();
        }
        if ($taken) {
            foreach ($uuids as $old => $_) {
                $uuids[$old] = (string) Str::uuid();
            }
            $notes[] = 'Die Inhalte gibt es in dieser Installation schon (Kopie einer Site) — alle Inhalte haben neue UUIDs.';
        }
        $entries = [];
        foreach (self::rows($payload, 'entries') as $row) {
            $entries[(string) $row['uuid']] = (int) DB::table('entries')->insertGetId(['site_id' => $siteId, 'type' => (string) $row['type'], 'uuid' => $uuids[(string) $row['uuid']],
                'parent_id' => null, 'position' => (int) $row['position'], 'settings' => null, 'redirect_to' => null, 'created_by' => $userId($row['created_by'] ?? null),
                'created_at' => $row['created_at'] ?? $now, 'updated_at' => $row['updated_at'] ?? $now, 'deleted_at' => $row['deleted_at'] ?? null]);
        }
        $lookup = function (string $kind, string $key) use ($media, $entries, $userId, $siteId): ?int {
            return match ($kind) {
                'media' => $this->known($media, $key, 'Medium'),
                'entry' => $this->known($entries, $key, 'Inhalt'),
                'user' => $userId($key),
                default => $siteId,
            };
        };
        $versions = [];
        $sources = [];
        foreach (self::rows($payload, 'entries') as $row) {
            $entryId = $entries[(string) $row['uuid']];
            DB::table('entries')->where('id', $entryId)->update([
                'parent_id' => isset($row['parent']) ? $this->known($entries, $row['parent'], 'Inhalt') : null,
                'settings' => self::encode(References::resolve($row['settings'] ?? null, $lookup)),
                'redirect_to' => self::encode(References::resolve($row['redirect_to'] ?? null, $lookup)),
            ]);
            foreach (is_array($row['versions'] ?? null) ? $row['versions'] : [] as $v) {
                $id = (int) DB::table('entry_versions')->insertGetId(['entry_id' => $entryId, 'locale' => (string) $v['locale'], 'source_version_id' => null, 'number' => (int) $v['number'],
                    'status' => (string) $v['status'], 'title' => (string) $v['title'], 'slug' => (string) $v['slug'], 'data' => self::encode(References::resolve($v['data'] ?? [], $lookup)),
                    'author_id' => $userId($v['author'] ?? null), 'submitted_at' => $v['submitted_at'] ?? null, 'submitted_by' => $userId($v['submitted_by'] ?? null),
                    'approved_by' => $userId($v['approved_by'] ?? null), 'comment' => $v['comment'] ?? null, 'reviewed_by' => $userId($v['reviewed_by'] ?? null),
                    'scheduled_for' => $v['scheduled_for'] ?? null, 'published_at' => $v['published_at'] ?? null, 'created_at' => $v['created_at'] ?? $now, 'updated_at' => $v['updated_at'] ?? $now]);
                $versions[$entryId.':'.$v['locale'].':'.$v['number']] = $id;
                if (is_array($v['source'] ?? null)) {
                    $sources[$id] = $entryId.':'.$v['source']['locale'].':'.$v['source']['number'];
                }
            }
        }
        foreach ($sources as $id => $key) {
            DB::table('entry_versions')->where('id', $id)->update(['source_version_id' => $this->known($versions, $key, 'Fassung')]);
        }
        $counts['entries'] = count($entries);
        $counts['versions'] = count($versions);
        DB::table('sites')->where('id', $siteId)->update(['settings' => self::encode(References::resolve($source['settings'] ?? null, $lookup))]);

        foreach (self::rows($payload, 'media_usages') as $row) {
            DB::table('media_usages')->insert(['media_id' => $this->known($media, $row['media'], 'Medium'), 'entry_id' => $this->known($entries, $row['entry'], 'Inhalt'),
                'locale' => (string) $row['locale'], 'field' => (string) $row['field'], 'created_at' => $row['created_at'] ?? $now, 'updated_at' => $row['updated_at'] ?? $now]);
        }
        $counts['media_usages'] = count(self::rows($payload, 'media_usages'));
        foreach (self::rows($payload, 'redirects') as $row) {
            DB::table('redirects')->insert(['site_id' => $siteId, 'locale' => (string) $row['locale'], 'from_path' => (string) $row['from_path'], 'to_path' => (string) $row['to_path'],
                'status' => (int) $row['status'], 'source' => (string) $row['source'], 'hits' => (int) ($row['hits'] ?? 0), 'last_hit_at' => $row['last_hit_at'] ?? null,
                'created_at' => $row['created_at'] ?? $now, 'updated_at' => $row['updated_at'] ?? $now]);
        }
        $counts['redirects'] = count(self::rows($payload, 'redirects'));
        $nav = [];
        foreach (self::rows($payload, 'navigation') as $row) {
            $nav[(string) $row['key']] = (int) DB::table('navigation_items')->insertGetId(['site_id' => $siteId, 'nav' => (string) $row['nav'], 'parent_id' => null, 'position' => (int) $row['position'],
                'entry_id' => isset($row['entry']) ? $this->known($entries, $row['entry'], 'Inhalt') : null, 'url' => $row['url'] ?? null, 'label' => self::encode($row['label'] ?? null),
                'visible' => (bool) $row['visible'], 'new_window' => (bool) $row['new_window'], 'created_at' => $row['created_at'] ?? $now, 'updated_at' => $row['updated_at'] ?? $now]);
        }
        foreach (self::rows($payload, 'navigation') as $row) {
            if (($row['parent'] ?? null) !== null) {
                DB::table('navigation_items')->where('id', $nav[(string) $row['key']])->update(['parent_id' => $this->known($nav, $row['parent'], 'Menüeintrag')]);
            }
        }
        $counts['navigation'] = count($nav);
        // Bausteine (TB 10 Scheibe 3, M-28) — Marken in den Blöcken werden zu den neuen Kennungen
        foreach (self::rows($payload, 'snippets') as $row) {
            DB::table('snippets')->insert(['site_id' => $siteId, 'handle' => (string) $row['handle'], 'name' => (string) $row['name'],
                'blocks' => self::encode(References::resolve($row['blocks'] ?? [], $lookup)), 'updated_by' => $userId($row['updated_by'] ?? null),
                'created_at' => $row['created_at'] ?? $now, 'updated_at' => $row['updated_at'] ?? $now]);
        }
        $counts['snippets'] = count(self::rows($payload, 'snippets'));
        $forms = [];
        foreach (self::rows($payload, 'forms') as $row) {
            $forms[(string) $row['handle']] = (int) DB::table('forms')->insertGetId(['site_id' => $siteId, 'handle' => (string) $row['handle'], 'webhook_secret' => null,
                'spam_count' => (int) $row['spam_count'], 'spam_last_at' => $row['spam_last_at'] ?? null, 'last_submission_at' => $row['last_submission_at'] ?? null,
                'created_at' => $row['created_at'] ?? $now, 'updated_at' => $row['updated_at'] ?? $now]);
            if ($row['webhook_secret_set'] ?? false) {
                $notes[] = sprintf('Formular «%s»: Webhook-Geheimnis neu erzeugen und dem Empfänger mitteilen (geht nicht mit dem Export mit).', $row['handle']);
            }
        }
        $counts['forms'] = count($forms);
        $submissionFiles = [];
        foreach (self::rows($payload, 'submissions') as $row) {
            $files = null;
            foreach (is_array($row['files'] ?? null) ? $row['files'] : [] as $field => $file) {
                $name = $file['file'] ?? null;
                $relative = is_string($name) && str_starts_with($name, 'submissions/') ? substr($name, strlen('submissions/')) : null;
                if ($relative === null || ! ArchiveReader::isSafeName($relative)) {
                    continue;
                }
                $path = 'forms/'.$siteId.'/'.$relative;
                $submissionFiles[$path] = (string) $name;
                $files[(string) $field] = ['path' => $path, 'name' => (string) $file['name'], 'size' => (int) $file['size'], 'mime' => (string) $file['mime']];
            }
            DB::table('form_submissions')->insert(['form_id' => $this->known($forms, $row['form'], 'Formular'), 'site_id' => $siteId, 'locale' => (string) $row['locale'],
                'page_path' => $row['page_path'] ?? null, 'data' => self::encode($row['data'] ?? []), 'files' => self::encode($files), 'ip_hash' => null, 'consent_at' => $row['consent_at'] ?? null,
                'status' => (string) $row['status'], 'mail_status' => $row['mail_status'] ?? null, 'mail_error' => $row['mail_error'] ?? null, 'webhook_status' => $row['webhook_status'] ?? null,
                'webhook_error' => $row['webhook_error'] ?? null, 'created_at' => $row['created_at'] ?? $now, 'updated_at' => $row['updated_at'] ?? $now]);
        }
        $counts['submissions'] = count(self::rows($payload, 'submissions'));

        // Dateien zuletzt — jede geschriebene wird gemerkt, damit ein Fehler sie wieder entfernt
        $counts['files'] = $this->copy($reader, $disk, $mediaFiles, $written) + $this->copy($reader, Storage::disk('local'), $submissionFiles, $written);

        return Site::query()->findOrFail($siteId);
    }

    /**
     * @param  array<string, string>  $files  Ziel im Speicher → Name im Archiv
     * @param  list<array{disk: Filesystem, path: string}>  $written
     */
    private function copy(ArchiveReader $reader, Filesystem $disk, array $files, array &$written): int
    {
        foreach ($files as $target => $name) {
            if (! $reader->has($name)) {
                throw new TransferException(sprintf('Datei «%s» fehlt im Archiv.', $name));
            }
            $stream = $reader->stream($name);
            $written[] = ['disk' => $disk, 'path' => $target];
            $ok = $disk->writeStream($target, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            if ($ok === false) {
                throw new TransferException(sprintf('Datei «%s» liess sich nicht speichern.', $target));
            }
        }

        return count($files);
    }

    /**
     * @template T
     *
     * @param  array<string, T>  $map
     * @return T
     */
    private function known(array $map, mixed $key, string $what): mixed
    {
        if (! is_string($key) || ! array_key_exists($key, $map)) {
            throw new TransferException(sprintf('%s «%s» fehlt im Export — Archiv unvollständig.', $what, is_scalar($key) ? (string) $key : '?'));
        }

        return $map[$key];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private static function rows(array $payload, string $key): array
    {
        $rows = $payload[$key] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** JSON wie die Eloquent-Casts der Modelle — null bleibt null. */
    private static function encode(mixed $value): ?string
    {
        return $value === null ? null : (string) json_encode($value);
    }
}
