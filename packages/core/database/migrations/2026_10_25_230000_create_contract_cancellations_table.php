<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR-011 TB 59: Kündigungen über «Verträge hier kündigen» (§ 312k BGB) — ohne Anmeldung eingegangen, mit Datum und
     * Uhrzeit des Eingangs als Nachweis. Zugeordnet ist der Vertrag, wenn die E-Mail-Adresse zu genau einem passt
     * (`subject_*`); sonst prüft das Team des Herstellers im Extranet.
     */
    public function up(): void
    {
        Schema::create('contract_cancellations', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 20)->unique();
            $table->string('kind', 20);
            $table->text('reason')->nullable();
            $table->string('name', 160);
            $table->string('email', 254);
            $table->string('contract', 40);
            $table->string('reference', 200)->nullable();
            $table->date('wish_date')->nullable();
            $table->string('locale', 10)->default('de');
            $table->string('status', 20);
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('handled_at')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['status', 'received_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_cancellations');
    }
};
