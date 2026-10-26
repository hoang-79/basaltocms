<?php

declare(strict_types=1);

use Basalto\Core\Search\SearchText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TB 71: Suche nach Wörtern ohne Unterschied von Grösse und Akzent — Titel und Text stehen zusätzlich gefaltet im
 * Suchdokument ({@see SearchText::fold()}). Bestehende Dokumente werden hier gefüllt, neue beim Speichern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_documents', function (Blueprint $table): void {
            $table->text('search_title')->nullable();
            $table->longText('search_body')->nullable();
        });
        DB::table('search_documents')->select(['id', 'title', 'body'])->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('search_documents')->where('id', $row->id)->update(['search_title' => SearchText::fold((string) $row->title), 'search_body' => SearchText::fold((string) $row->body)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('search_documents', function (Blueprint $table): void {
            $table->dropColumn(['search_title', 'search_body']);
        });
    }
};
