<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Lizenzstand der Installation (K-18, D-12): seit wann sie unregistriert ist — nach 30 Tagen zeigt das Frontend einen Hinweis. Eine Zeile. */
    public function up(): void
    {
        Schema::create('license_state', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('unregistered_since')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_state');
    }
};
