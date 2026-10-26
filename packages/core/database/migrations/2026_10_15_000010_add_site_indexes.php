<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lasttest Prüfpunkt 8: PostgreSQL und SQLite legen für Fremdschlüssel keinen Index an (MySQL schon) — die Medienliste
     * je Site las 333'000 Zeilen sequentiell (15 ms statt 1 ms). Zusammengesetzte Indizes passen zu «neueste je Site» und
     * «primäre Domain je Site» und sind auf allen Treibern sinnvoll.
     */
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->index(['site_id', 'id']);
        });
        Schema::table('site_domains', function (Blueprint $table): void {
            $table->index(['site_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropIndex(['site_id', 'id']);
        });
        Schema::table('site_domains', function (Blueprint $table): void {
            $table->dropIndex(['site_id', 'is_primary']);
        });
    }
};
