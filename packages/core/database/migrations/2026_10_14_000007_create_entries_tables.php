<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inhalte (I-03, R-16): ein Eintrag je Site und Typ (Lava «type»); je Sprache Fassungen mit Nummer, Status,
     * Titel, Slug und Feldern als JSON (Blöcke inklusive). Slug ist je Sprache; Eindeutigkeit unter veröffentlichten
     * Fassungen prüft die Anwendung beim Veröffentlichen (nur ein Teil der Fassungen ist live).
     */
    public function up(): void
    {
        Schema::create('entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('type', 60);
            $table->uuid('uuid')->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['site_id', 'type']);
        });
        Schema::create('entry_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entry_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->unsignedInteger('number');
            $table->string('status', 20);
            $table->string('title');
            $table->string('slug');
            $table->json('data');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['entry_id', 'locale', 'number']);
            $table->index(['locale', 'slug', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_versions');
        Schema::dropIfExists('entries');
    }
};
