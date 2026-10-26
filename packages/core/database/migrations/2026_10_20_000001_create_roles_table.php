<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-005 (R-03): Rollen als Daten je Installation bzw. Konto — eigene Rollen und angepasste Vorlagen. Zugewiesen wird
 * je Site über `site_user.role` mit dem Schlüssel der Rolle; Vorlagen ohne Zeile gelten aus dem Code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('name', 120);
            $table->text('description')->nullable();
            // Bereich → Stufe (0 keine … 4 alles), «*» für alle Bereiche
            $table->json('levels');
            // null = alle Sprachen; {"mode":"except_default"} oder {"mode":"only","codes":["fr"]}
            $table->json('languages')->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
