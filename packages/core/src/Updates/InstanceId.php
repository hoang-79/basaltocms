<?php

declare(strict_types=1);

namespace Basalto\Core\Updates;

use Basalto\Core\Models\Setting;
use Basalto\Core\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Zufällige Kennung dieser Installation für die Update-Prüfung (TB 55). Sie entsteht bei der ersten Prüfung, hängt
 * an nichts (keine Adresse, kein Konto) und geht nur mit, solange «Nutzungsangaben an den Hersteller» an ist. Der
 * Hersteller zählt damit aktive Installationen und Versionen; er speichert nur ihre Prüfsumme.
 */
final class InstanceId
{
    public const KEY = 'operations.instance_id';

    public const SETTING = 'operations.usage_report';

    /**
     * Kopfzeilen für die Update-Prüfung — leer, wenn ausgeschaltet.
     *
     * @return array<string, string>
     */
    public static function headers(): array
    {
        try {
            if (! (bool) app(Settings::class)->get(self::SETTING, true)) {
                return [];
            }

            return ['X-Basalto-Instance' => self::get(), 'X-Basalto-Php' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
                'X-Basalto-Database' => (string) DB::connection()->getDriverName()];
        } catch (Throwable) {
            return [];
        }
    }

    public static function get(): string
    {
        $value = Setting::query()->whereNull('site_id')->where('key', self::KEY)->value('value');
        if (is_string($value) && Str::isUuid($value)) {
            return $value;
        }
        $id = (string) Str::uuid();
        // Ohne Verlauf und Protokoll: keine Einstellung, die jemand ändert
        Setting::query()->updateOrCreate(['site_id' => null, 'key' => self::KEY], ['value' => $id]);

        return $id;
    }
}
