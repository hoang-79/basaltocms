<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kommentare (M-27) nach dem Datenmodell: je Site und Inhalt, Antworten über `parent_id` (eine Ebene), Status
     * `pending`, `approved`, `spam`, `deleted`, IP nur als Prüfsumme. Ergänzt um `user_id` (Antwort aus dem Backend,
     * «null on delete»), `consent_at` (Zustimmung im Formular, N-05) und `updated_at` (Beginn der Frist im Löschlauf).
     * Erkannter Spam wird nie gespeichert, nur gezählt — je Site und Tag in `comment_spam_counts`.
     */
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('comments')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('locale', 10);
            $table->string('author_name', 120);
            $table->string('author_email', 254)->nullable();
            $table->string('author_url', 500)->nullable();
            $table->text('body');
            $table->string('status', 20)->default('pending');
            $table->char('ip_hash', 64)->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status', 'created_at']);
            $table->index(['entry_id', 'status']);
            $table->index('author_email');
        });

        Schema::create('comment_spam_counts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('count')->default(0);

            $table->unique(['site_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_spam_counts');
        Schema::dropIfExists('comments');
    }
};
