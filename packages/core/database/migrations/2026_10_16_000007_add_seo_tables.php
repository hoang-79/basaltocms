<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SEO (M-10, TB 06): das 404-Protokoll je Site und Pfad mit Zähler, erstem und letztem Treffer und der zuletzt
     * verweisenden Seite; Umleitungen merken sich den letzten Treffer. Nur neue Tabelle und neue Spalte — rückwärtskompatibel (B-11).
     */
    public function up(): void
    {
        Schema::create('not_found_hits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('locale', 5);
            $table->unsignedInteger('hits')->default(0);
            $table->string('referrer', 500)->nullable();
            $table->dateTime('first_seen_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'path']);
            $table->index(['site_id', 'last_seen_at']);
        });
        Schema::table('redirects', function (Blueprint $table): void {
            $table->dateTime('last_hit_at')->nullable()->after('hits');
        });
    }

    public function down(): void
    {
        Schema::table('redirects', function (Blueprint $table): void {
            $table->dropColumn('last_hit_at');
        });
        Schema::dropIfExists('not_found_hits');
    }
};
