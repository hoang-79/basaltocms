<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `basalto:publish` schaltet eine Site live (L-06): bis dahin sehen Gäste «Bald online» (503, noindex), Angemeldete
     * die Seite. Bestehende Sites gelten als live — nur neue Spalte, rückwärtskompatibel (B-11).
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dateTime('live_at')->nullable();
        });
        DB::table('sites')->whereNull('live_at')->update(['live_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('live_at');
        });
    }
};
