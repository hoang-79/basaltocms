<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** TB 52 (CR-009 Nr. 10): die Moderation sperrt das Bearbeiten eines einzelnen Beitrags. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forum_posts', static function (Blueprint $table): void {
            $table->boolean('is_edit_locked')->default(false)->after('is_official');
        });
    }

    public function down(): void
    {
        Schema::table('forum_posts', static function (Blueprint $table): void {
            $table->dropColumn('is_edit_locked');
        });
    }
};
