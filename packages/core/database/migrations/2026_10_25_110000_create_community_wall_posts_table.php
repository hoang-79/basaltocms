<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** TB 52 (CR-009 Nr. 19): Pinnwand im Profil — Einträge anderer Mitglieder auf dem Profil einer Person. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_wall_posts', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained('community_profiles')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->json('body');
            $table->timestamps();
            $table->index(['site_id', 'profile_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_wall_posts');
    }
};
