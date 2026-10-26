<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR-011 TB 60: Zustimmung eines Kundenkontos zu den Bedingungen der Community — je Fassung eine Zeile mit Zeitpunkt,
     * beim Widerruf mit `revoked_at`. Die Zeilen bleiben als Nachweis, bis das Konto geschlossen wird.
     */
    public function up(): void
    {
        Schema::create('community_terms_consents', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('version', 40);
            $table->timestamp('accepted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_terms_consents');
    }
};
