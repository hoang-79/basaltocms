<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Domains je Site (G-11): Alias, Kundendomain, Umleitung — mit Stand der Anlage auf Laravel Cloud. */
    public function up(): void
    {
        Schema::create('site_domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('host')->unique();
            $table->string('kind', 20);
            $table->boolean('is_primary')->default(false);
            $table->string('cloud_domain_id')->nullable();
            $table->string('cloud_status', 40)->nullable();
            $table->json('cloud_response')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_domains');
    }
};
