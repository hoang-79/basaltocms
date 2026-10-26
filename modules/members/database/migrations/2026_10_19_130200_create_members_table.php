<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mitglieder (M-23, TB 13 Scheibe 2): eine Mitgliedschaft je Frontend-Konto (`customers`, je Site) mit Stand —
     * wartet auf Freigabe, aktiv oder gesperrt — und wer wann eingeladen, freigegeben oder gesperrt hat. Name, E-Mail
     * und Sprache stehen am Konto; die Mitgliedschaft trägt nur, was das Konto zum Mitglied macht.
     */
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->timestamp('invited_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('blocked_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
