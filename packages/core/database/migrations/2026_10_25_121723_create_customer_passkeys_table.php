<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TB 53: Passkeys für Kundenkonten im Frontend — wie `passkeys` der Backend-Benutzer, aber je Site und am
     * Kundenkonto. Die Relying Party ist der Host der Site, ein Passkey gilt also auf der Domain, auf der er entstand.
     */
    public function up(): void
    {
        Schema::create('customer_passkeys', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('credential_id')->unique();
            $table->json('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_passkeys');
    }
};
