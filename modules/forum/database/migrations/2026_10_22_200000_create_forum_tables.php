<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-006 (TB 36): das Forum — Knoten (Kategorie, Forum, Link) als Baum, Themen und Beiträge. Zähler und «letzter
 * Beitrag» werden in den Aktionen gepflegt statt beim Anzeigen gezählt. Konten können verschwinden (`nullOnDelete`),
 * ihre Beiträge bleiben (FO-23: anonymisiert statt gelöscht).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forum_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('forum_nodes')->nullOnDelete();
            $table->string('type', 12);
            $table->string('slug', 80);
            $table->unsignedInteger('position')->default(0);
            $table->json('title');
            $table->json('description')->nullable();
            $table->string('link_url')->nullable();
            $table->json('settings')->nullable();
            $table->unsignedInteger('threads_count')->default(0);
            $table->unsignedInteger('posts_count')->default(0);
            $table->unsignedBigInteger('last_post_id')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'slug']);
            $table->index(['site_id', 'parent_id', 'position']);
        });

        Schema::create('forum_threads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->constrained('forum_nodes')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('title', 160);
            $table->string('slug', 120);
            $table->string('locale', 10);
            $table->boolean('is_sticky')->default(false);
            $table->boolean('is_closed')->default(false);
            $table->boolean('is_disabled')->default(false);
            $table->unsignedBigInteger('first_post_id')->nullable();
            $table->unsignedBigInteger('last_post_id')->nullable();
            $table->foreignId('last_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamp('last_posted_at')->nullable();
            $table->unsignedInteger('replies')->default(0);
            $table->unsignedInteger('views')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['node_id', 'is_sticky', 'last_posted_at']);
        });

        Schema::create('forum_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained('forum_threads')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->json('body');
            $table->boolean('is_disabled')->default(false);
            $table->timestamp('edited_at')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['thread_id', 'id']);
            $table->index(['customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_posts');
        Schema::dropIfExists('forum_threads');
        Schema::dropIfExists('forum_nodes');
    }
};
