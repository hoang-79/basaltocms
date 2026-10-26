<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-006 (TB 37): Schreiben im Forum — Präfixe (Labels je Forum, FO-09), Werte der Themenfelder, Bearbeiten mit
 * Verlauf und Grund (FO-08). Ein Fassungseintrag hält den Text **vor** der Änderung fest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forum_prefixes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->json('label');
            $table->string('color', 20)->default('grey');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
        Schema::table('forum_threads', function (Blueprint $table): void {
            $table->foreignId('prefix_id')->nullable()->after('locale')->constrained('forum_prefixes')->nullOnDelete();
            $table->json('form_values')->nullable()->after('views');
        });
        Schema::table('forum_posts', function (Blueprint $table): void {
            $table->unsignedInteger('edit_count')->default(0)->after('edited_at');
            $table->string('edit_reason', 200)->nullable()->after('edit_count');
            $table->foreignId('edited_by')->nullable()->after('edit_reason')->constrained('customers')->nullOnDelete();
        });
        Schema::create('forum_post_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('forum_posts')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->json('body');
            $table->string('title', 160)->nullable();
            $table->string('reason', 200)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['post_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_post_revisions');
        Schema::table('forum_posts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('edited_by');
            $table->dropColumn(['edit_count', 'edit_reason']);
        });
        Schema::table('forum_threads', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('prefix_id');
            $table->dropColumn('form_values');
        });
        Schema::dropIfExists('forum_prefixes');
    }
};
