<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TB 54: Statistik im Frontend (wie Fristo) — ein Ereignis je Seitenaufruf, bewusst denormalisiert für schnelle
     * Auswertung ohne Joins. Besucher und Sitzung nur mit Einwilligung (pseudonyme Kennungen aus Cookies, im Browser
     * erzeugt), sonst leer: der Aufruf zählt anonym. Keine IP, keine volle Adresse, vom Verweis nur der Host.
     */
    public function up(): void
    {
        Schema::create('analytics_page_views', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('visitor_id', 40)->default('');
            $table->string('session_id', 40)->default('');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('path', 512);
            $table->unsignedSmallInteger('status')->default(200);
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->string('referrer_host', 255)->nullable();
            $table->string('utm_source', 120)->nullable();
            $table->string('utm_medium', 120)->nullable();
            $table->string('utm_campaign', 120)->nullable();
            $table->string('browser', 60)->nullable();
            $table->string('os', 60)->nullable();
            $table->string('device_type', 20)->nullable();
            $table->boolean('is_bot')->default(false);
            $table->string('country', 2)->nullable();
            $table->string('locale', 12)->nullable();
            // Testdaten (`basalto:testdaten`) — lassen sich gezielt entfernen
            $table->boolean('is_synthetic')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->index(['site_id', 'created_at', 'is_bot']);
            $table->index(['site_id', 'visitor_id']);
            $table->index(['site_id', 'session_id']);
            $table->index(['site_id', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_page_views');
    }
};
