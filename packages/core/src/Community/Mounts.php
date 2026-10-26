<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\Backend\Backend;
use Basalto\Core\Content\Pages;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\Site;

/**
 * Einhängeseiten (CR-006 FO-K1): Ein Modul meldet einen Einhängepunkt an (`forum`), eine Seite im Seitenbaum trägt ihn
 * im Reiter «Experten» (`expert.mount`). Die Seite selbst und alle Pfade darunter beantwortet dann das Modul — im
 * Layout des Themes, mit Titel, Brotkrumen und Metadaten der Seite. Nur eingeschaltete Module zählen; ist das Modul
 * aus, ist die Seite eine gewöhnliche Seite und die Pfade darunter antworten 404.
 *
 * Die Anmeldungen stehen in der Konfiguration statt in einer statischen Eigenschaft (Octane).
 */
final class Mounts
{
    public const CONFIG = 'basalto.mounts';

    /**
     * Beim Booten des Moduls aufrufen.
     *
     * @param  class-string<MountHandler>  $handler
     */
    public function register(string $handle, string $label, string $handler, ?string $module = null): void
    {
        config()->set(self::CONFIG.'.'.$handle, ['label' => $label, 'handler' => $handler, 'module' => $module ?? $handle]);
    }

    /** @return array<string, array{label: string, handler: class-string<MountHandler>, module: string}> */
    public function all(): array
    {
        $out = [];
        foreach ((array) config(self::CONFIG, []) as $handle => $mount) {
            if (is_string($handle) && is_array($mount) && is_string($mount['handler'] ?? null) && is_a($mount['handler'], MountHandler::class, true)) {
                $out[$handle] = ['label' => (string) ($mount['label'] ?? $handle), 'handler' => $mount['handler'], 'module' => (string) ($mount['module'] ?? $handle)];
            }
        }

        return $out;
    }

    /**
     * Die Einhängepunkte, die auf dieser Site wählbar sind (Modul an) — für den Reiter «Experten».
     *
     * @return list<array{value: string, label: string}>
     */
    public function options(Site $site): array
    {
        $backend = app(Backend::class);
        $out = [];
        foreach ($this->all() as $handle => $mount) {
            if ($backend->moduleEnabled($mount['module'], $site)) {
                $out[] = ['value' => $handle, 'label' => __($mount['label'])];
            }
        }

        return $out;
    }

    /** @return list<string> alle angemeldeten Kennungen — für die Prüfung beim Speichern */
    public function handles(): array
    {
        return array_keys($this->all());
    }

    /** Der Einhängepunkt einer Seite, wenn sie einen trägt und das Modul auf ihrer Site an ist. */
    public function handleOf(Entry $entry): ?string
    {
        $handle = $entry->setting('expert.mount');
        if (! is_string($handle) || $handle === '') {
            return null;
        }
        $mount = $this->all()[$handle] ?? null;
        if ($mount === null || ! app(Backend::class)->moduleEnabled($mount['module'], $entry->site)) {
            return null;
        }

        return $handle;
    }

    public function handler(string $handle): MountHandler
    {
        return app($this->all()[$handle]['handler']);
    }

    /**
     * Die Seite mit diesem Einhängepunkt auf einer Site — für Verweise aus Benachrichtigungen, Profilen und Extranet.
     * Die erste nach Kennung, wenn mehrere ihn tragen.
     */
    public function entryFor(Site $site, string $handle): ?Entry
    {
        return Entry::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereNull('deleted_at')->orderBy('id')->get()
            ->first(fn (Entry $entry): bool => $entry->setting('expert.mount') === $handle && $entry->resolve($site->default_locale) !== null);
    }

    /**
     * Die Adresse der Community einer Site (TB 40) — die Seite des ersten Einhängepunkts, der dort hängt; unter ihr
     * leben Profile und Konversationen. Ohne Schrägstrich am Ende, null ohne Community.
     */
    public function baseFor(Site $site, ?string $locale = null): ?string
    {
        foreach ($this->handles() as $handle) {
            $entry = $this->entryFor($site, $handle);
            $url = $entry === null ? null : app(Pages::class)->urlOf($entry, $locale ?? $site->default_locale);
            if ($url !== null) {
                return rtrim($url, '/');
            }
        }

        return null;
    }
}
