<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Suche (M-12): eine Zeile je veröffentlichtem Inhalt und Sprache — Titel, Text aus allen Feldern und Blöcken,
     * Pfad. Laravel Scout durchsucht sie mit dem Datenbank-Treiber; mit Meilisearch wird dieselbe Tabelle indexiert.
     */
    public function up(): void
    {
        Schema::create('search_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entry_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('type', 60);
            $table->string('title');
            $table->longText('body');
            $table->string('path', 500);
            $table->dateTime('published_at')->nullable();
            $table->timestamps();
            $table->unique(['entry_id', 'locale']);
            $table->index(['site_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_documents');
    }
};
