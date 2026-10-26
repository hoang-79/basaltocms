<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Formulare (M-11, TB 07): Die Definition ist Lava (Theme oder Site-Quelle `forms.lava`); `forms` hält, was nicht
     * ins Theme gehört — Webhook-Geheimnis, Spam-Zähler, letzter Eingang. `form_submissions` sind die Eingänge:
     * Werte als JSON, Dateien privat, IP nur als Prüfsumme, Zeitpunkt der Zustimmung, Status von Mail und Webhook.
     * Gelöscht wird endgültig (N-05), nach Frist im Löschlauf.
     */
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('handle', 60);
            $table->text('webhook_secret')->nullable();
            $table->unsignedInteger('spam_count')->default(0);
            $table->dateTime('spam_last_at')->nullable();
            $table->dateTime('last_submission_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'handle']);
            $table->index('site_id');
        });
        Schema::create('form_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('page_path', 250)->nullable();
            $table->json('data');
            $table->json('files')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->dateTime('consent_at')->nullable();
            $table->string('status', 20)->default('new');
            $table->string('mail_status', 20)->nullable();
            $table->string('mail_error', 500)->nullable();
            $table->string('webhook_status', 20)->nullable();
            $table->string('webhook_error', 500)->nullable();
            $table->timestamps();
            $table->index(['site_id', 'created_at']);
            $table->index(['form_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('forms');
    }
};
