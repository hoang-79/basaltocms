<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TB 27 (Benachrichtigungen): die Tabelle des Datenbank-Kanals von Laravel, ergänzt um die Art (`kind`, Schlüssel aus
 * dem Verzeichnis), die Schicht (`backend` oder `extranet`) und die Site — so zeigen Glocke und Center je Schicht und
 * Site, was dorthin gehört. Ohne Site gilt eine Benachrichtigung überall (Konto, Sicherheit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->string('kind', 80);
            $table->string('scope', 10)->default('backend');
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['notifiable_type', 'notifiable_id', 'scope', 'read_at'], 'notifications_bell_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
