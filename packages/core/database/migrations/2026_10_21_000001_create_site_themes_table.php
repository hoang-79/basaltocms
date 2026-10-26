<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TB 22 (Theme-Bibliothek): Eine Site hält beliebig viele eigene Themes. Jedes hat seine Dateien in `lava_sources`
 * (`scope = theme:<id>`), seine Entwürfe und Fassungen — bisher gab es genau ein Datenbank-Theme je Site
 * (`scope = theme`). Ein vorhandenes wird zum ersten Eintrag der Bibliothek, unter dem Namen des Theme-Ordners, den es
 * ersetzt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_themes', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('label', 120)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('version', 40)->nullable();
            // ordner (übernommen), kopie, paket (ZIP), marktplatz
            $table->string('origin', 20)->default('ordner');
            $table->string('origin_name', 60)->nullable();
            $table->boolean('signed')->default(false);
            // Bilder und Schriften auf der Standard-Disk: Pfad → {hash, size} — Adressen ohne die Datei zu lesen
            $table->json('assets')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['site_id', 'name']);
        });
        Schema::table('theme_drafts', static function (Blueprint $table): void {
            $table->foreignId('site_theme_id')->nullable()->after('site_id')->constrained('site_themes')->cascadeOnDelete();
            $table->index('site_id');
            $table->unique(['site_theme_id', 'path']);
        });
        Schema::table('theme_versions', static function (Blueprint $table): void {
            $table->foreignId('site_theme_id')->nullable()->after('site_id')->constrained('site_themes')->cascadeOnDelete();
            $table->index('site_id');
            $table->unique(['site_theme_id', 'number']);
        });

        $default = (string) config('basalto.default_theme', 'nguyen-development');
        $siteIds = DB::table('lava_sources')->where('scope', 'theme')->distinct()->pluck('site_id');
        foreach ($siteIds as $siteId) {
            $name = (string) (DB::table('sites')->where('id', $siteId)->value('theme') ?? $default);
            $now = now();
            $id = (int) DB::table('site_themes')->insertGetId(['site_id' => $siteId, 'name' => $name, 'origin' => 'ordner', 'origin_name' => $name, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('lava_sources')->where('site_id', $siteId)->where('scope', 'theme')->update(['scope' => 'theme:'.$id]);
            DB::table('theme_drafts')->where('site_id', $siteId)->update(['site_theme_id' => $id]);
            DB::table('theme_versions')->where('site_id', $siteId)->update(['site_theme_id' => $id]);
        }
        // Entwürfe ohne Theme gab es nur zu einem Datenbank-Theme — ohne eines sind sie verwaist
        DB::table('theme_drafts')->whereNull('site_theme_id')->delete();

        Schema::table('theme_drafts', static function (Blueprint $table): void {
            $table->dropUnique(['site_id', 'path']); // zero-downtime: der alte Code sucht über site_id und path, braucht die Eindeutigkeit aber nicht — mehrere Themes je Site schliessen sie aus
        });
        Schema::table('theme_versions', static function (Blueprint $table): void {
            $table->dropUnique(['site_id', 'number']); // zero-downtime: wie oben — Fassungen zählen je Theme, nicht je Site
        });
    }

    public function down(): void
    {
        foreach (DB::table('site_themes')->get(['id', 'site_id', 'name']) as $theme) {
            $active = DB::table('sites')->where('id', $theme->site_id)->value('theme') === $theme->name;
            if ($active) {
                DB::table('lava_sources')->where('scope', 'theme:'.$theme->id)->update(['scope' => 'theme']);
            } else {
                DB::table('lava_sources')->where('scope', 'theme:'.$theme->id)->delete();
                DB::table('theme_drafts')->where('site_theme_id', $theme->id)->delete();
                DB::table('theme_versions')->where('site_theme_id', $theme->id)->delete();
            }
        }
        Schema::table('theme_drafts', static function (Blueprint $table): void {
            $table->unique(['site_id', 'path']);
            $table->dropUnique(['site_theme_id', 'path']);
            $table->dropIndex(['site_id']);
            $table->dropConstrainedForeignId('site_theme_id');
        });
        Schema::table('theme_versions', static function (Blueprint $table): void {
            $table->unique(['site_id', 'number']);
            $table->dropUnique(['site_theme_id', 'number']);
            $table->dropIndex(['site_id']);
            $table->dropConstrainedForeignId('site_theme_id');
        });
        Schema::dropIfExists('site_themes');
    }
};
