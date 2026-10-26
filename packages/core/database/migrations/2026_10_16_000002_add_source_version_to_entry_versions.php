<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Übersetzung (M-06): auf welcher Fassung der Standardsprache eine Sprachfassung beruht — daraus folgen «veraltet» und «geändert». */
    public function up(): void
    {
        Schema::table('entry_versions', function (Blueprint $table): void {
            $table->foreignId('source_version_id')->nullable()->after('locale')->constrained('entry_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('entry_versions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_version_id');
        });
    }
};
