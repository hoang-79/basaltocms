<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Theme-Dateien als Datensätze je Site — Grundlage für den Lava-Editor im Backend (R-97). */
    public function up(): void
    {
        Schema::create('lava_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('site');
            $table->string('path');
            $table->text('source');
            $table->timestamps();
            $table->unique(['site', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lava_sources');
    }
};
