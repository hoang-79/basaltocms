<?php

declare(strict_types=1);

namespace Basalto\Core\Settings;

use Basalto\Core\Activity\AutosaveRequest;
use Basalto\Core\Activity\Recorder;
use Basalto\Core\Models\Setting;
use Basalto\Core\Models\SettingRevision;
use Basalto\Core\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Einstellungen mit Verlauf (K-07): je Site oder installationsweit; `set()` schreibt nur bei Änderung eine Revision,
 * `restore()` stellt einen früheren Wert wieder her — als neue Revision, nie durch Löschen des Verlaufs.
 */
final class Settings
{
    /**
     * Wert einer Einstellung — ohne Zeile in `settings` gilt bei Site-Werten der Eintrag in `sites.settings`
     * (so standen Vier-Augen und Alt-Text-Pflicht vor TB 05), sonst der Startwert des Aufrufers.
     */
    public function get(string $key, mixed $default = null, ?Site $site = null): mixed
    {
        $setting = $this->find($key, $site);
        if ($setting !== null) {
            return $setting->value;
        }

        return $site !== null ? $site->setting($key, $default) : $default;
    }

    /**
     * Setzen mit Verlauf. Ohne bisherige Zeile ist der alte Wert der aus `sites.settings` oder der Startwert des
     * Aufrufers — so steht im Verlauf «aus → ein», nicht «— → ein».
     */
    public function set(string $key, mixed $value, ?Site $site = null, ?Authenticatable $user = null, mixed $default = null): Setting
    {
        $setting = $this->find($key, $site) ?? new Setting(['site_id' => $site?->id, 'key' => $key]);
        $old = $setting->exists ? $setting->value : ($site !== null ? $site->setting($key, $default) : $default);
        if ($setting->exists && $old === $value) {
            return $setting;
        }
        $userId = $user === null ? null : (int) $user->getAuthIdentifier();
        $setting->value = $value;
        $setting->updated_by = $userId;
        $setting->save();
        $this->revise($setting, $old, $value, $userId);
        // Jede Änderung einer Einstellung steht auch im Protokoll (K-15)
        app(Recorder::class)->record('setting.changed', $setting, ['value' => $old], ['key' => $key, 'value' => $value], $site, $user);

        return $setting;
    }

    /** @return Collection<int, SettingRevision> */
    public function history(string $key, ?Site $site = null): Collection
    {
        $setting = $this->find($key, $site);

        return $setting === null ? new Collection : $setting->revisions()->get();
    }

    /** Stellt den Wert vor einer Revision wieder her. */
    public function restore(SettingRevision $revision, ?Authenticatable $user = null): Setting
    {
        $setting = $revision->setting()->firstOrFail();
        $site = $setting->site_id === null ? null : Site::query()->find($setting->site_id);

        return $this->set($setting->key, $revision->old, $site, $user);
    }

    /**
     * Verlauf schreiben; eine Folge von Autosaves derselben Person ist ein Eintrag (CR-013) — führt sie zurück auf den
     * Ausgangswert, fällt der Eintrag weg.
     */
    private function revise(Setting $setting, mixed $old, mixed $value, ?int $userId): void
    {
        $now = CarbonImmutable::now();
        if ($userId !== null && AutosaveRequest::current()) {
            $recent = $setting->revisions()->where('user_id', $userId)->where('created_at', '>=', AutosaveRequest::since())->latest('id')->first();
            if ($recent instanceof SettingRevision) {
                if ($recent->old === $value) {
                    $recent->delete();
                } else {
                    $recent->new = $value;
                    $recent->created_at = $now;
                    $recent->save();
                }

                return;
            }
        }
        $setting->revisions()->create(['old' => $old, 'new' => $value, 'user_id' => $userId, 'created_at' => $now]);
    }

    private function find(string $key, ?Site $site): ?Setting
    {
        $query = Setting::query()->where('key', $key);
        $site === null ? $query->whereNull('site_id') : $query->where('site_id', $site->id);

        return $query->first();
    }
}
