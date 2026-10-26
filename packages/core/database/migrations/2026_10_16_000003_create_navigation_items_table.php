<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Freie Menüeinträge (M-07, Station D): je Site und Menü des Themes (`nav <handle> { items: db }`), verschachtelt,
     * Ziel Seite oder URL, Beschriftung je Sprache. Die Menüs selbst leben im Theme.
     */
    public function up(): void
    {
        Schema::create('navigation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('nav', 60);
            $table->foreignId('parent_id')->nullable()->constrained('navigation_items')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('entry_id')->nullable()->constrained()->nullOnDelete();
            $table->string('url', 500)->nullable();
            $table->json('label')->nullable();
            $table->boolean('visible')->default(true);
            $table->boolean('new_window')->default(false);
            $table->timestamps();
            $table->index(['site_id', 'nav', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('navigation_items');
    }
};
