<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TB 12 Scheibe 4 (M-21, ADR-008): Austritt aus der SaaS und Umzug zwischen Instanzen.
     *
     * - `accounts.ends_at`: Ende der Laufzeit nach einer Kündigung (bezahlte Periode, in der Testphase sofort); danach
     *   gesperrt bis `delete_after` (30 Tage), dann gelöscht — `erased_at` hält fest, wann (Konto anonymisiert, bleibt
     *   wegen der Rechnungen). `instance_target`: Zielinstanz eines Planwechsels («start» ↔ «pro»), gesetzt von Scheibe 2.
     * - `account_exports`: Exporte aus dem Konto-Bereich (je Site oder ganzes Konto) mit Fortschritt, 7 Tage abrufbar.
     * - `instance_moves`: Umzüge eines Kontos — auf der Quelle `out`, auf dem Ziel `in`, verbunden über die UUID.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->timestamp('ends_at')->nullable();
            $table->string('instance_target', 10)->nullable();
            $table->timestamp('erased_at')->nullable();
        });
        Schema::create('account_exports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20);
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('disk', 40)->nullable();
            $table->string('path')->nullable();
            $table->string('filename')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'created_at']);
            $table->index('expires_at');
        });
        Schema::create('instance_moves', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid');
            $table->string('direction', 3);
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 10);
            $table->string('target', 10);
            $table->string('status', 20);
            $table->string('disk', 40)->nullable();
            $table->string('path')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['uuid', 'direction']);
            $table->index(['account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instance_moves');
        Schema::dropIfExists('account_exports');
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['ends_at', 'instance_target', 'erased_at']);
        });
    }
};
