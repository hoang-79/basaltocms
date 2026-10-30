<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-017 (TB 87): Unterdomains, die Basalto beim Hoster selbst einrichtet — Stand, letzter Fehler, letzter und nächster
 * Versuch, Ablauf des eingespielten Zertifikats (`basalto:hostnamen`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_domains', function (Blueprint $table): void {
            $table->string('hosting_status', 16)->nullable();
            $table->string('hosting_error', 500)->nullable();
            $table->timestamp('hosting_checked_at')->nullable();
            $table->timestamp('hosting_retry_at')->nullable();
            $table->timestamp('certificate_expires_at')->nullable();
            $table->index('hosting_status');
        });
    }

    public function down(): void
    {
        Schema::table('site_domains', function (Blueprint $table): void {
            $table->dropIndex(['hosting_status']);
            $table->dropColumn(['hosting_status', 'hosting_error', 'hosting_checked_at', 'hosting_retry_at', 'certificate_expires_at']);
        });
    }
};
