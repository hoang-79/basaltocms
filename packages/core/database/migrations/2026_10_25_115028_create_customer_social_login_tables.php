<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TB 53 (CR-009 Nr. 2): Anmeldung über Drittanbieter für Kundenkonten im Frontend. `customer_login_providers` hält je
     * Site und Anbieter (Google, Microsoft, GitHub, LinkedIn) die App des Betreibers — das Client-Secret verschlüsselt
     * mit dem APP_KEY. `customer_identities` verknüpft ein Kundenkonto mit der Kennung beim Anbieter; ist sie einmal
     * verknüpft, zählt die Kennung, nicht mehr die E-Mail.
     */
    public function up(): void
    {
        Schema::create('customer_login_providers', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->boolean('enabled')->default(false);
            $table->string('client_id', 200);
            $table->text('client_secret');
            // Microsoft: «common» (private und Firmenkonten) oder «consumers» (nur private)
            $table->string('tenant', 40)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['site_id', 'provider']);
        });
        Schema::create('customer_identities', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('subject', 191);
            $table->string('email', 254)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'provider', 'subject']);
            $table->index(['customer_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_identities');
        Schema::dropIfExists('customer_login_providers');
    }
};
