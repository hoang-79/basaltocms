<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-006 (TB 41, FO-K12): Suche über Module — ein Suchdokument stammt nicht mehr nur aus einer Seite (`entry`), sondern
 * aus einer Quelle eines Moduls (`forum` mit der Kennung des Themas). Seiten behalten `entry_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_documents', function (Blueprint $table): void {
            $table->unsignedBigInteger('entry_id')->nullable()->change(); // zero-downtime: nur gelockert — der alte Code setzt entry_id weiterhin
            $table->string('source', 40)->default('entry');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->index(['source', 'source_id']);
            $table->index(['site_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::table('search_documents', function (Blueprint $table): void {
            $table->dropIndex(['site_id', 'source']);
            $table->dropIndex(['source', 'source_id']);
            $table->dropColumn(['source', 'source_id']);
        });
    }
};
