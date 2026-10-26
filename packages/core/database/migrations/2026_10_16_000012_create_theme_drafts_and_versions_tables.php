<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Themes in der Datenbank mit Lava-Editor (L-17, TB 10): Entwürfe je Datei neben den Live-Quellen (`lava_sources`) und
 * Fassungen als Schnappschuss jeder Veröffentlichung — wiederherstellbar, freigegeben wie Inhalte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_drafts', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('path', 190);
            // null = Datei soll mit der Veröffentlichung wegfallen
            $table->longText('source')->nullable();
            // Prüfsumme des Live-Stands beim Beginn des Entwurfs — erkennt, wenn jemand dazwischen veröffentlicht hat
            $table->string('base_hash', 64)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['site_id', 'path']);
        });

        Schema::create('theme_versions', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            // alle Dateien des Themes als JSON {pfad: inhalt}
            $table->longText('files');
            $table->string('note', 500)->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_versions');
        Schema::dropIfExists('theme_drafts');
    }
};
