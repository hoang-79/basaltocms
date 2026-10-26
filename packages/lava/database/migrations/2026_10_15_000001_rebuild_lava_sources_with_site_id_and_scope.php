<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * lava_sources hängt an der Site-Kennung statt am Handle und kennt einen Geltungsbereich: «theme» (das Theme der
     * Site) oder «site» (site-eigene Typen aus dem Backend, TB 01 Scheibe 3). Bestehende Zeilen werden über das Handle
     * auf die Kennung umgeschrieben; Zeilen ohne auflösbare Site fallen weg. Tabelle wird neu aufgebaut, damit SQLite,
     * MariaDB und PostgreSQL denselben Weg gehen.
     */
    public function up(): void
    {
        $rows = DB::table('lava_sources')->get();
        Schema::drop('lava_sources');
        Schema::create('lava_sources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('scope', 20)->default('theme');
            $table->string('path');
            $table->text('source');
            $table->timestamps();
            $table->unique(['site_id', 'scope', 'path']);
            if (DB::getDriverName() !== 'sqlite') {
                $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            }
        });
        $siteIds = DB::table('sites')->pluck('id', 'handle');
        foreach ($rows as $row) {
            $siteId = $siteIds[(string) $row->site] ?? null;
            if ($siteId === null) {
                continue;
            }
            DB::table('lava_sources')->insert([
                'site_id' => (int) $siteId,
                'scope' => 'theme',
                'path' => $row->path,
                'source' => $row->source,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        $rows = DB::table('lava_sources')->where('scope', 'theme')->get();
        Schema::drop('lava_sources');
        Schema::create('lava_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('site');
            $table->string('path');
            $table->text('source');
            $table->timestamps();
            $table->unique(['site', 'path']);
        });
        $handles = DB::table('sites')->pluck('handle', 'id');
        foreach ($rows as $row) {
            $handle = $handles[(int) $row->site_id] ?? null;
            if ($handle === null) {
                continue;
            }
            DB::table('lava_sources')->insert([
                'site' => (string) $handle,
                'path' => $row->path,
                'source' => $row->source,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }
    }
};
