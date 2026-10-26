<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-006 (TB 41, FO-21): Schlagwörter je Thema mit eigener Seite und «Empfohlen» für Themen, die das Team hervorhebt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forum_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('name', 30);
            $table->string('slug', 40);
            $table->timestamps();
            $table->unique(['site_id', 'slug']);
        });
        Schema::create('forum_thread_tag', function (Blueprint $table): void {
            $table->foreignId('thread_id')->constrained('forum_threads')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('forum_tags')->cascadeOnDelete();
            $table->primary(['thread_id', 'tag_id']);
            $table->index('tag_id');
        });
        Schema::table('forum_threads', function (Blueprint $table): void {
            $table->boolean('is_featured')->default(false);
            $table->index(['site_id', 'is_featured']);
        });
    }

    public function down(): void
    {
        Schema::table('forum_threads', function (Blueprint $table): void {
            $table->dropIndex(['site_id', 'is_featured']);
            $table->dropColumn('is_featured');
        });
        Schema::dropIfExists('forum_thread_tag');
        Schema::dropIfExists('forum_tags');
    }
};
