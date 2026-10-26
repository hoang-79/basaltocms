<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * API-Tokens (M-17): die Tabelle von Laravel Sanctum (Spalten wie in der veröffentlichten Migration von
     * laravel/sanctum 4.3) und dazu die Site, für die ein Token gilt. Die Site steht in einer eigenen Spalte statt in
     * den Fähigkeiten — so hängt sie mit Fremdschlüssel an der Site (fällt mit ihr weg), lässt sich abfragen und kann
     * nicht als zusätzliche Fähigkeit «dazugeschrieben» werden. Ein Token ohne Site gilt für die API nicht.
     */
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->foreignId('site_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
