<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-018 (TB 103): Zahlungen einer Site über das eigene Stripe-Konto des Betreibers — der Schlüssel je Site
 * (verschlüsselt) und jede Zahlung mit Modul, Bezug, Betrag und Stand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_payment_settings', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 20)->default('stripe');
            $table->text('secret_key')->nullable();
            $table->string('currency', 3)->default('CHF');
            $table->string('account_name', 190)->nullable();
            $table->boolean('live')->default(false);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
        Schema::create('payments', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('module', 60);
            $table->string('reference', 120);
            $table->string('token', 64);
            $table->unsignedInteger('amount');
            $table->string('currency', 3);
            $table->string('description', 250);
            $table->string('status', 12)->default('open');
            $table->boolean('live')->default(false);
            $table->string('provider_session', 255)->nullable();
            $table->string('provider_payment', 255)->nullable();
            $table->string('checkout_url', 1000)->nullable();
            $table->string('return_url', 1000);
            $table->string('email', 190)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'module', 'reference']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('site_payment_settings');
    }
};
