<?php

declare(strict_types=1);

use Basalto\Core\Console\DatabaseTimezoneCommand;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TB 64: Eine Neuinstallation schreibt mit MariaDB/MySQL von Anfang an in UTC (`DB_TIMEZONE`, Vorgabe `+00:00`). Ohne
 * Vermerk hielte `basalto:db-zeitzone` ihre Zeitpunkte für in der Server-Zone geschrieben und empföhle ein Umrechnen,
 * das sie um eine bis zwei Stunden verschöbe. Darum vermerkt diese Migration auf einer leeren Datenbank mit UTC-Session,
 * dass nichts umzurechnen ist. Ein Bestand (Konten oder Sites vorhanden) bleibt ohne Vermerk — dort entscheidet der
 * Befehl.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection();
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        if ((string) ($db->selectOne('SELECT @@session.time_zone AS v')->v ?? '') !== '+00:00') {
            return;
        }
        if ($db->table('users')->exists() || $db->table('sites')->exists()) {
            return;
        }
        if ($db->table('settings')->whereNull('site_id')->where('key', DatabaseTimezoneCommand::SETTING)->exists()) {
            return;
        }
        $now = CarbonImmutable::now();
        $db->table('settings')->insert(['site_id' => null, 'key' => DatabaseTimezoneCommand::SETTING, 'created_at' => $now, 'updated_at' => $now,
            'value' => json_encode(['from' => '+00:00', 'at' => $now->toIso8601String(), 'vermerk' => 'Neuinstallation — von Anfang an in UTC'])]);
    }

    public function down(): void
    {
        // zero-downtime: der Vermerk bleibt — ohne ihn empföhle der Befehl ein falsches Umrechnen
    }
};
