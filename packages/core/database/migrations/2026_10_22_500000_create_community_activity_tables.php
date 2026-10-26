<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-006 (TB 38): Aktivität in der Community — Abonnements und Ignorieren (FO-10), Reaktionen (FO-K8) und Einstellungen
 * des Kundenkontos für Benachrichtigungen (FO-K7, `customers.preferences` wie `users.preferences`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('mode', 10);
            $table->timestamp('created_at')->nullable();
            $table->unique(['customer_id', 'subject_type', 'subject_id'], 'community_subscriptions_unique');
            $table->index(['subject_type', 'subject_id', 'mode']);
        });
        Schema::create('community_reactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('reaction', 20);
            $table->timestamp('created_at')->nullable();
            $table->unique(['customer_id', 'subject_type', 'subject_id'], 'community_reactions_unique');
            $table->index(['subject_type', 'subject_id']);
        });
        Schema::table('customers', function (Blueprint $table): void {
            $table->json('preferences')->nullable();
        });
        Schema::table('community_profiles', function (Blueprint $table): void {
            $table->unsignedInteger('reactions_received')->default(0)->after('posts');
        });
    }

    public function down(): void
    {
        Schema::table('community_profiles', function (Blueprint $table): void {
            $table->dropColumn('reactions_received');
        });
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('preferences');
        });
        Schema::dropIfExists('community_reactions');
        Schema::dropIfExists('community_subscriptions');
    }
};
