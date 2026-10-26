<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** TB 52 (CR-009 Nr. 40): ein Rang darf ein Zeichen tragen (Emoji wie bei den Trophäen) statt des farbigen Punkts. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('community_ranks', static function (Blueprint $table): void {
            $table->string('icon', 16)->nullable()->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('community_ranks', static function (Blueprint $table): void {
            $table->dropColumn('icon');
        });
    }
};
