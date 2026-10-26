<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Passwortlose Anmeldung (K-04, K-05): Einmal-Links und Mail-Codes, nur als Hash gespeichert, mit Ablauf, Verbrauch und Versuchszähler. */
    public function up(): void
    {
        Schema::create('login_challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);
            $table->string('purpose', 20);
            $table->string('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('created_from_ip', 45)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'kind']);
            $table->index('token_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_challenges');
    }
};
