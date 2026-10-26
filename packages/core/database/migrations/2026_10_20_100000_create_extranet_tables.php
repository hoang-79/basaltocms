<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-004 (P-09–P-16): Hersteller-Extranet — Team mit Rolle, Notizen zur Kundenakte (je E-Mail-Adresse, damit sie
 * Konto und Lizenzkauf derselben Person verbinden), Gutschriften mit eigener Nummer und befristeter Support-Zugriff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extranet_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('role', 64);
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('extranet_notes', function (Blueprint $table): void {
            $table->id();
            $table->string('email', 254)->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('credit_notes', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 20);
            $table->unsignedBigInteger('invoice_id');
            $table->string('invoice_number', 24);
            $table->string('number', 24)->unique();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('sequence');
            $table->char('currency', 3);
            $table->unsignedInteger('amount');
            $table->string('reason', 500);
            $table->string('email', 254);
            $table->json('debtor');
            $table->string('locale', 10)->default('de');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('issued_at');
            $table->timestamps();
            $table->unique(['year', 'sequence']);
            $table->index(['source', 'invoice_id']);
        });

        Schema::create('support_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 500);
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'expires_at']);
            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_sessions');
        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('extranet_notes');
        Schema::dropIfExists('extranet_members');
    }
};
