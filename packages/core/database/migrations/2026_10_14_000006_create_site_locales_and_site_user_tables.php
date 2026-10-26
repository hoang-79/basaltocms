<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Sprachen je Site mit Standard, Pflicht und Fallback (R-20); Benutzer je Site mit Rolle (K-01, K-06); Einstellungen je Site. */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('timezone', 64)->default('Europe/Zurich')->after('default_locale');
            $table->string('theme')->nullable()->after('timezone');
            $table->json('settings')->nullable()->after('theme');
        });
        Schema::create('site_locales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_required')->default(false);
            $table->string('fallback_locale', 5)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['site_id', 'locale']);
        });
        Schema::create('site_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 30);
            $table->timestamps();
            $table->unique(['site_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_user');
        Schema::dropIfExists('site_locales');
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['timezone', 'theme', 'settings']);
        });
    }
};
