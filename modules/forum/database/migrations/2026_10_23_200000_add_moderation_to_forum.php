<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-006 (TB 39, FO-16): Moderation im Forum — Ankündigungen, Verweise nach dem Verschieben (mit Ablauf) und
 * Zusammenführen, Grund beim Papierkorb. `is_disabled` (seit TB 36) heisst «wartet auf die Moderation»: Freischaltung
 * oder vorläufig ausgeblendet nach Meldungen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forum_threads', function (Blueprint $table): void {
            $table->boolean('is_announcement')->default(false);
            // Verweis-Thema: zeigt auf das verschobene oder zusammengeführte Thema
            $table->unsignedBigInteger('moved_to_id')->nullable();
            $table->timestamp('redirect_until')->nullable();
            $table->string('trash_reason', 500)->nullable();
            $table->index(['site_id', 'is_disabled']);
            $table->index(['site_id', 'is_announcement']);
        });
        Schema::table('forum_posts', function (Blueprint $table): void {
            $table->string('trash_reason', 500)->nullable();
            $table->index(['site_id', 'is_disabled']);
        });
    }

    public function down(): void
    {
        Schema::table('forum_posts', function (Blueprint $table): void {
            $table->dropIndex(['site_id', 'is_disabled']);
            $table->dropColumn('trash_reason');
        });
        Schema::table('forum_threads', function (Blueprint $table): void {
            $table->dropIndex(['site_id', 'is_announcement']);
            $table->dropIndex(['site_id', 'is_disabled']);
            $table->dropColumn(['is_announcement', 'moved_to_id', 'redirect_until', 'trash_reason']);
        });
    }
};
