<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TB 12 Scheibe 3 (G-11): Domains der SaaS — die Umleitung eines alten Alias läuft bis `redirect_until` (30 Tage,
     * danach räumt der Scheduler sie weg und der Name ist frei); `pair_id` verbindet `www` und Apex einer Kundendomain
     * (die Zeile ohne `pair_id` trägt den Eintrag in der Cloud); `checked_at` ist die letzte Prüfung über die Cloud-CLI.
     * Ohne Fremdschlüssel auf sich selbst (SQLite kann ihn nicht nachträglich anlegen) — das Entfernen löscht das Paar.
     */
    public function up(): void
    {
        Schema::table('site_domains', function (Blueprint $table): void {
            $table->unsignedBigInteger('pair_id')->nullable()->index();
            $table->timestamp('redirect_until')->nullable();
            $table->timestamp('checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('site_domains', function (Blueprint $table): void {
            $table->dropIndex(['pair_id']);
            $table->dropColumn(['pair_id', 'redirect_until', 'checked_at']);
        });
    }
};
