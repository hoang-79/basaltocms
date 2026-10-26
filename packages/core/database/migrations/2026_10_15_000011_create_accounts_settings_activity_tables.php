<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TB 00: Konto über Sites (ADR-008), Einstellungen mit Verlauf (K-07) und Protokoll (K-15) — Tabellen aus dem
     * Datenmodell v0.3. Bestehende Sites bekommen ein Konto «Installation» (Download = genau ein Konto).
     */
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('plan', 20)->default('download');
            $table->string('status', 20)->default('active');
            $table->timestamp('trial_ends_at')->nullable();
            $table->json('billing')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('delete_after')->nullable();
            $table->string('instance', 10)->nullable();
            $table->timestamps();
        });
        Schema::create('account_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->timestamps();
            $table->unique(['account_id', 'user_id']);
        });
        Schema::table('sites', function (Blueprint $table): void {
            $table->unsignedBigInteger('account_id')->nullable()->after('id')->index();
        });
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('sites', function (Blueprint $table): void {
                $table->foreign('account_id')->references('id')->on('accounts')->nullOnDelete();
            });
        }
        if (DB::table('sites')->whereNull('account_id')->exists()) {
            $accountId = DB::table('accounts')->insertGetId(['name' => (string) config('app.name', 'Basalto'), 'plan' => 'download', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('sites')->whereNull('account_id')->update(['account_id' => $accountId]);
            foreach (DB::table('site_user')->where('role', 'owner')->distinct()->pluck('user_id') as $userId) {
                DB::table('account_user')->insert(['account_id' => $accountId, 'user_id' => $userId, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 120);
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['site_id', 'key']);
        });
        Schema::create('setting_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('setting_id')->constrained()->cascadeOnDelete();
            $table->json('old')->nullable();
            $table->json('new')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->index(['setting_id', 'created_at']);
        });
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('action', 60);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('created_at');
            $table->index(['site_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
        Schema::dropIfExists('setting_revisions');
        Schema::dropIfExists('settings');
        Schema::table('sites', function (Blueprint $table): void {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['account_id']);
            }
            $table->dropColumn('account_id');
        });
        Schema::dropIfExists('account_user');
        Schema::dropIfExists('accounts');
    }
};
