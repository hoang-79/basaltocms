<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-006 (TB 39, FO-K9): Moderation im Kern — Meldungen für jeden Inhaltstyp (die Meldungen zu Bewertungen ziehen aus
 * dem Marktplatz hierher), das Moderationsprotokoll (Team im Extranet und moderierende Kundenkonten), Verwarnungen mit
 * Punkten, Sperren am Community-Profil und Hinweise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            // marketplace_rating, forum_post, …
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            // «k:<id>» für Kundenkonten, sonst HMAC der IP mit dem App-Schlüssel — erkennt doppelte Meldungen
            $table->string('reporter', 64);
            $table->string('reason', 20);
            $table->string('note', 500)->nullable();
            // open, hidden (Inhalt ausgeblendet), kept (Meldung zurückgewiesen)
            $table->string('status', 20)->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by_customer')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['subject_type', 'subject_id', 'reporter'], 'community_reports_unique');
            $table->index(['site_id', 'status']);
        });

        Schema::create('community_moderation_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('action', 40);
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label', 200)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            // die betroffene Person (Verfasserin, verwarntes Konto)
            $table->foreignId('target_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['site_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['target_customer_id']);
        });

        Schema::create('community_warnings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('reason', 200);
            $table->unsignedSmallInteger('points');
            $table->string('note', 500)->nullable();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by_customer')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'expires_at']);
        });

        Schema::table('community_profiles', function (Blueprint $table): void {
            // Schreibsperre und Sperre — ohne Ende (null) gelten sie, bis die Moderation sie aufhebt
            $table->timestamp('muted_at')->nullable();
            $table->timestamp('muted_until')->nullable();
            $table->timestamp('banned_at')->nullable();
            $table->timestamp('banned_until')->nullable();
            $table->string('ban_reason', 500)->nullable();
        });

        Schema::create('community_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->json('text');
            // info, warnung, erfolg
            $table->string('tone', 10)->default('info');
            // Bedingungen: Gruppen (leer = alle), Bereich («*» oder «forum:<id>»), angemeldet/abgemeldet
            $table->json('conditions')->nullable();
            $table->boolean('dismissible')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'is_active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_notices');
        Schema::table('community_profiles', function (Blueprint $table): void {
            $table->dropColumn(['muted_at', 'muted_until', 'banned_at', 'banned_until', 'ban_reason']);
        });
        Schema::dropIfExists('community_warnings');
        Schema::dropIfExists('community_moderation_log');
        Schema::dropIfExists('community_reports');
    }
};
