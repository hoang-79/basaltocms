<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backend-Benutzer (K-03): Einladung per Link mit Ablauf je Site und Rolle; Konto deaktivieren; Profil minimal
     * (Sprache der Oberfläche, Zeitzone).
     */
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 20);
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'email']);
        });
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'deactivated_at')) {
                $table->timestamp('deactivated_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'locale')) {
                $table->string('locale', 5)->nullable();
            }
            if (! Schema::hasColumn('users', 'timezone')) {
                $table->string('timezone', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['deactivated_at', 'locale', 'timezone']);
        });
    }
};
