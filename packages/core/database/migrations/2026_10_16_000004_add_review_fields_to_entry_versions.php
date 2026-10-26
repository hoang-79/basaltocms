<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Workflow (M-08): wer wann eingereicht hat und der Kommentar einer Rückweisung — die Übergänge selbst stehen im Protokoll. */
    public function up(): void
    {
        Schema::table('entry_versions', function (Blueprint $table): void {
            $table->timestamp('submitted_at')->nullable()->after('author_id');
            $table->foreignId('submitted_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable()->after('approved_by');
            $table->foreignId('reviewed_by')->nullable()->after('comment')->constrained('users')->nullOnDelete();
            $table->index(['status', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::table('entry_versions', function (Blueprint $table): void {
            $table->dropIndex(['status', 'scheduled_for']);
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['submitted_at', 'comment']);
        });
    }
};
