<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-022: Werte, die eine Site in einer Auswahlliste ergänzt hat (Lebenszyklus, Art der Aktivität, Funktion …), und ihre
 * Pflege — umbenannt, sortiert, ausgeblendet. Vorgegebene Werte stehen im Code; eine Zeile zu einem vorgegebenen Wert
 * hält nur Reihenfolge und Ausblenden fest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('choice_options', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('list', 80);
            $table->string('value', 120);
            $table->string('label', 160)->nullable();
            $table->string('group', 120)->nullable();
            $table->integer('position')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'list', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('choice_options');
    }
};
