<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TB 12 Scheibe 1 (M-21, G-11): Selbstregistrierung auf der SaaS — offene Registrierungen mit Mail-Code und
     * Einmal-Link (vor der Bestätigung gibt es weder Konto noch Benutzer), Hinweise je Konto genau einmal (Erinnerungen
     * der Testphase, später Rechnungen und Kündigung) und der Zeitpunkt der Zustimmung zu AGB und Datenschutz am Konto.
     */
    public function up(): void
    {
        Schema::create('saas_registrations', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('locale', 5);
            $table->string('code_hash', 64)->nullable();
            $table->timestamp('code_expires_at')->nullable();
            $table->string('link_hash', 64)->nullable()->unique();
            $table->timestamp('link_expires_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('terms_accepted_at');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();
            $table->index('email');
            $table->index('created_at');
        });
        Schema::create('account_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 60);
            $table->string('reference', 60)->default('');
            $table->timestamp('sent_at');
            $table->timestamps();
            $table->unique(['account_id', 'kind', 'reference']);
        });
        Schema::table('accounts', function (Blueprint $table): void {
            $table->timestamp('terms_accepted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn('terms_accepted_at');
        });
        Schema::dropIfExists('account_notices');
        Schema::dropIfExists('saas_registrations');
    }
};
