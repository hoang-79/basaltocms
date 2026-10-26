<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bausteine (M-28, TB 10): wiederverwendbare Blockgruppen je Site. Seiten verweisen mit einem Block `@snippet` auf den
 * Baustein — eine Änderung hier wirkt überall, wo er steht. `blocks` ist die Blockliste wie in `entry_versions.data.content`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snippets', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('handle', 64);
            $table->string('name', 120);
            $table->json('blocks');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['site_id', 'handle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snippets');
    }
};
