<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seitenbaum (M-01, Datenmodell v0.4): Eltern, Position, sprachunabhängige Seiteneinstellungen und das Ziel einer
     * Weiterleitung am Eintrag; Umleitungen je Site und Sprache (Slug-Wechsel, Verschieben, von Hand).
     */
    public function up(): void
    {
        Schema::table('entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('parent_id')->nullable()->after('uuid');
            $table->unsignedInteger('position')->default(0)->after('parent_id');
            $table->json('settings')->nullable()->after('position');
            $table->json('redirect_to')->nullable()->after('settings');
            $table->index(['site_id', 'parent_id', 'position']);
        });
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('entries', function (Blueprint $table): void {
                $table->foreign('parent_id')->references('id')->on('entries')->nullOnDelete();
            });
        }
        Schema::create('redirects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('from_path');
            $table->string('to_path');
            $table->unsignedSmallInteger('status')->default(301);
            $table->string('source', 20)->default('manual');
            $table->unsignedInteger('hits')->default(0);
            $table->timestamps();
            $table->unique(['site_id', 'locale', 'from_path']);
            $table->index('site_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');
        Schema::table('entries', function (Blueprint $table): void {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['parent_id']);
            }
            $table->dropIndex(['site_id', 'parent_id', 'position']);
            $table->dropColumn(['parent_id', 'position', 'settings', 'redirect_to']);
        });
    }
};
