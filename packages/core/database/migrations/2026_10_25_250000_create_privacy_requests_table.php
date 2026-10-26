<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR-011 TB 61: Anliegen zum Datenschutz aus dem Kundenkonto (Berichtigung, Einschränkung, Widerspruch, Sonstiges) —
     * mit Frist von einem Monat (Art. 12 Abs. 3 DSGVO); erledigt im Backend der Site unter Verwaltung › Datenschutz.
     */
    public function up(): void
    {
        Schema::create('privacy_requests', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('email', 254);
            $table->string('name', 160)->nullable();
            $table->string('kind', 30);
            $table->text('message');
            $table->string('locale', 10)->default('de');
            $table->string('status', 20)->default('open');
            $table->timestamp('received_at');
            $table->timestamp('due_at');
            $table->timestamp('handled_at')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('answer')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_requests');
    }
};
