<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-006 (TB 38): Aktivität im Forum — Lesestand je Thema und Forum (FO-10; Forum 0 = «alles gelesen»), hilfreichste
 * Antwort und erledigt, offizielle Beiträge, Umfragen (FO-12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forum_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained('forum_threads')->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->unique(['customer_id', 'thread_id']);
        });
        Schema::create('forum_node_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->unsignedBigInteger('node_id');
            $table->timestamp('read_at');
            $table->unique(['customer_id', 'node_id']);
        });
        Schema::table('forum_threads', function (Blueprint $table): void {
            $table->unsignedBigInteger('best_post_id')->nullable()->after('last_post_id');
            $table->boolean('is_done')->default(false)->after('is_disabled');
        });
        Schema::table('forum_posts', function (Blueprint $table): void {
            $table->boolean('is_official')->default(false)->after('body');
            $table->unsignedInteger('reactions')->default(0)->after('is_official');
        });
        Schema::create('forum_polls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->unique()->constrained('forum_threads')->cascadeOnDelete();
            $table->string('question', 200);
            $table->unsignedTinyInteger('max_choices')->default(1);
            $table->boolean('results_after_vote')->default(true);
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('voters')->default(0);
            $table->timestamps();
        });
        Schema::create('forum_poll_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('poll_id')->constrained('forum_polls')->cascadeOnDelete();
            $table->string('label', 120);
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedInteger('votes')->default(0);
        });
        Schema::create('forum_poll_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('poll_id')->constrained('forum_polls')->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('forum_poll_options')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['option_id', 'customer_id']);
            $table->index(['poll_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_poll_votes');
        Schema::dropIfExists('forum_poll_options');
        Schema::dropIfExists('forum_polls');
        Schema::table('forum_posts', function (Blueprint $table): void {
            $table->dropColumn(['is_official', 'reactions']);
        });
        Schema::table('forum_threads', function (Blueprint $table): void {
            $table->dropColumn(['best_post_id', 'is_done']);
        });
        Schema::dropIfExists('forum_node_reads');
        Schema::dropIfExists('forum_reads');
    }
};
