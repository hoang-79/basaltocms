<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-006 (TB 37, FO-K6): Anhänge von Besuchern — nicht in der Mediathek der Redaktion, sondern je Konto. Ohne
 * `subject` (leer, 0) ist ein Anhang hochgeladen, aber noch an keinen Beitrag gebunden; der Löschlauf räumt solche
 * nach einem Tag weg. Bilder sind neu kodiert (WebP, ohne Metadaten).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('subject_type', 40)->default('');
            $table->unsignedBigInteger('subject_id')->default(0);
            $table->string('path');
            $table->string('name', 200);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_attachments');
    }
};
