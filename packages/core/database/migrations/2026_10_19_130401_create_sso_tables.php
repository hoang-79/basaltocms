<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TB 13 Scheibe 4 (M-25): SSO für Backend-Benutzer. `sso_connections` hält je Site und Anbieter (Microsoft Entra ID,
     * Google) die App-Registrierung — das Client-Secret verschlüsselt mit dem APP_KEY (Text, weil der Chiffretext länger
     * ist als das Secret). `sso_identities` verknüpft einen Benutzer beim ersten Mal mit der Kennung beim Anbieter
     * (Entra: Objekt-ID im Tenant, Google: `sub`) — danach zählt die Kennung, nicht mehr die E-Mail.
     */
    public function up(): void
    {
        Schema::create('sso_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->boolean('enabled')->default(true);
            $table->string('tenant_id', 36)->nullable();
            $table->string('client_id', 200);
            $table->text('client_secret');
            $table->string('hosted_domain', 190)->nullable();
            $table->string('auto_create_role', 30)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['site_id', 'provider']);
        });
        Schema::create('sso_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            // Entra: Tenant-ID; Google: «google» — die Kennung ist nur zusammen mit dem Aussteller eindeutig
            $table->string('issuer', 64);
            $table->string('subject', 191);
            $table->string('email');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'issuer', 'subject']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_identities');
        Schema::dropIfExists('sso_connections');
    }
};
