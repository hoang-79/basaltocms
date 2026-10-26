<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-006 (TB 40): Profile und Konversationen — Cover, Über mich und eigene Felder am Profil, Folgen und Blockieren
 * (FO-K10), Konversationen mit mehreren Teilnehmern (FO-K11), Ränge, Trophäen und Regeln für die automatische
 * Gruppenzuordnung (FO-K13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('community_profiles', function (Blueprint $table): void {
            $table->string('cover_path')->nullable();
            $table->text('about')->nullable();
            $table->json('fields')->nullable();
        });

        Schema::create('community_follows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('followed_id')->constrained('customers')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['customer_id', 'followed_id']);
            $table->index('followed_id');
        });

        Schema::create('community_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('blocked_id')->constrained('customers')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['customer_id', 'blocked_id']);
            $table->index('blocked_id');
        });

        Schema::create('community_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('subject', 160);
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('last_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('messages')->default(0);
            $table->timestamps();
            $table->index(['site_id', 'last_message_at']);
        });

        Schema::create('community_conversation_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('community_conversations')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['conversation_id', 'customer_id'], 'community_conv_participants_unique');
            $table->index('customer_id');
        });

        Schema::create('community_conversation_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('community_conversations')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->json('body')->nullable();
            // von der Moderation entfernt (nach Meldung) — der Text ist weg, die Stelle bleibt
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'id']);
        });

        Schema::create('community_ranks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('title', 60);
            $table->foreignId('visitor_group_id')->nullable()->constrained('visitor_groups')->cascadeOnDelete();
            $table->unsignedInteger('min_posts')->default(0);
            $table->string('color', 10)->default('grey');
            $table->timestamps();
            $table->index(['site_id', 'min_posts']);
        });

        Schema::create('community_trophies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('title', 80);
            $table->string('description', 255)->nullable();
            $table->string('icon', 16)->default('🏆');
            // Regel aus dem Register (null = nur von Hand vergeben) und ihre Schwelle
            $table->string('rule', 40)->nullable();
            $table->unsignedInteger('threshold')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('community_customer_trophies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trophy_id')->constrained('community_trophies')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('awarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('awarded_at')->nullable();
            $table->unique(['trophy_id', 'customer_id']);
            $table->index('customer_id');
        });

        Schema::create('community_group_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visitor_group_id')->constrained('visitor_groups')->cascadeOnDelete();
            $table->string('rule', 40);
            $table->unsignedInteger('threshold')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        Schema::table('visitor_group_customer', function (Blueprint $table): void {
            // gesetzt von einer Regel (dann nimmt die Regel die Mitgliedschaft auch wieder weg), sonst von Hand
            $table->unsignedBigInteger('rule_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('visitor_group_customer', function (Blueprint $table): void {
            $table->dropColumn('rule_id');
        });
        Schema::dropIfExists('community_group_rules');
        Schema::dropIfExists('community_customer_trophies');
        Schema::dropIfExists('community_trophies');
        Schema::dropIfExists('community_ranks');
        Schema::dropIfExists('community_conversation_messages');
        Schema::dropIfExists('community_conversation_participants');
        Schema::dropIfExists('community_conversations');
        Schema::dropIfExists('community_blocks');
        Schema::dropIfExists('community_follows');
        Schema::table('community_profiles', function (Blueprint $table): void {
            $table->dropColumn(['cover_path', 'about', 'fields']);
        });
    }
};
