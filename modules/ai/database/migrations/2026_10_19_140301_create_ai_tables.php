<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * KI-Vorschläge (M-24): je Site eine Einstellung mit Anbieter, Modell, dem Schlüssel des Kunden (verschlüsselt mit
     * dem App-Schlüssel, nie im Klartext), Tageslimit, Kostenbremse in Tokens je Monat und Schaltern je Funktion — und
     * das Protokoll jeder Nutzung: Funktion, Benutzer, Gegenstand, Anbieter und Modell, Tokens, Erfolg oder Fehler.
     * Das Protokoll trägt nie Inhalte und nie den Schlüssel.
     */
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 40)->default('openai');
            $table->string('model', 120)->nullable();
            $table->text('api_key')->nullable();
            $table->unsignedInteger('daily_limit')->default(100);
            $table->unsignedBigInteger('monthly_token_limit')->nullable();
            $table->json('features')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('feature', 20);
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('locale', 10)->nullable();
            $table->string('provider', 40);
            $table->string('model', 120)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->string('status', 10);
            $table->string('error', 40)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['site_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usages');
        Schema::dropIfExists('ai_settings');
    }
};
