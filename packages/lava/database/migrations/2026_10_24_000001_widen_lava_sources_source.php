<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lava einer Site kann grösser als 64 KB werden (die Funktionsschau der Demo in vier Sprachen hat über 100 KB) —
     * unter MariaDB/MySQL endet `TEXT` dort. `LONGTEXT` wie bei den Theme-Entwürfen; SQLite und PostgreSQL kennen die
     * Grenze nicht.
     */
    public function up(): void
    {
        Schema::table('lava_sources', function (Blueprint $table): void {
            $table->longText('source')->change(); // zero-downtime: nur verbreitert — der alte Code liest und schreibt weiter Text
        });
    }

    public function down(): void
    {
        Schema::table('lava_sources', function (Blueprint $table): void {
            $table->text('source')->change();
        });
    }
};
