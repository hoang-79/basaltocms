<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-019 (TB 107): Adressen, die Basalto über IndexNow an Suchmaschinen meldet — wartend, gemeldet oder gescheitert,
 * mit Antwort der Schnittstelle. Zugleich das Protokoll für Verwaltung › Suchmaschinen und die Statistik.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indexnow_urls', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('url', 512);
            $table->string('status', 10)->default('pending');
            $table->unsignedSmallInteger('response')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->index(['site_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indexnow_urls');
    }
};
