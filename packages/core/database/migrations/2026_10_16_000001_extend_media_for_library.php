<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mediathek (K-08): Ordner je Site, Metadaten (Titel, Urheber, Alt-Text je Sprache), Fokuspunkt, Papierkorb,
     * Verwendungsnachweis; `site_id` wird Pflicht — Medien ohne Site gehören der ersten Site, sonst entfallen sie.
     */
    public function up(): void
    {
        Schema::create('media_folders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('media_folders')->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['site_id', 'parent_id']);
        });

        $firstSite = DB::table('sites')->orderBy('id')->value('id');
        if ($firstSite !== null) {
            DB::table('media')->whereNull('site_id')->update(['site_id' => $firstSite]);
        } else {
            DB::table('media')->whereNull('site_id')->delete();
        }

        Schema::table('media', function (Blueprint $table): void {
            $table->foreignId('folder_id')->nullable()->after('site_id')->constrained('media_folders')->nullOnDelete();
            $table->string('title', 200)->nullable()->after('name');
            $table->string('credit', 200)->nullable()->after('title');
            $table->json('alt')->nullable()->after('credit');
            $table->decimal('focus_x', 4, 3)->default(0.5)->after('height');
            $table->decimal('focus_y', 4, 3)->default(0.5)->after('focus_x');
            $table->unsignedBigInteger('created_by')->nullable()->after('variants_generated_at');
            $table->softDeletes();
            $table->index(['site_id', 'folder_id']);
            $table->index(['site_id', 'deleted_at']);
        });
        // Pflicht-Site: der alte Fremdschlüssel setzte auf NULL — jetzt fällt ein Medium mit seiner Site
        Schema::table('media', function (Blueprint $table): void {
            $table->dropForeign(['site_id']);
        });
        Schema::table('media', function (Blueprint $table): void {
            $table->foreignId('site_id')->nullable(false)->change();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
        });

        Schema::create('media_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->foreignId('entry_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('field', 160);
            $table->timestamps();
            $table->unique(['media_id', 'entry_id', 'locale', 'field']);
            $table->index(['entry_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_usages');
        Schema::table('media', function (Blueprint $table): void {
            $table->dropIndex(['site_id', 'folder_id']);
            $table->dropIndex(['site_id', 'deleted_at']);
            $table->dropConstrainedForeignId('folder_id');
            $table->dropColumn(['title', 'credit', 'alt', 'focus_x', 'focus_y', 'created_by', 'deleted_at']);
        });
        Schema::dropIfExists('media_folders');
    }
};
