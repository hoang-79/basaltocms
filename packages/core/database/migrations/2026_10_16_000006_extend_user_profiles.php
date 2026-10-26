<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Profil (K-03, Wunsch Hoang 16.10.2026): Vor- und Nachname getrennt, Telefon je Land (E.164), persönliche
     * Anschrift, Einstellungen für die Darstellung. `name` bleibt als «Vorname Nachname» für Listen und Protokoll.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('first_name', 80)->nullable()->after('name');
            $table->string('last_name', 80)->nullable()->after('first_name');
            $table->string('phone', 20)->nullable();
            $table->string('phone_country', 2)->nullable();
            $table->string('street', 120)->nullable();
            $table->string('address_extra', 120)->nullable();
            $table->string('postal_code', 12)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('country', 2)->nullable();
            $table->json('preferences')->nullable();
        });
        // Bestehende Namen aufteilen: das letzte Wort ist der Nachname
        foreach (DB::table('users')->get(['id', 'name']) as $user) {
            $parts = preg_split('/\s+/', trim((string) $user->name)) ?: [];
            $last = count($parts) > 1 ? (string) array_pop($parts) : null;
            DB::table('users')->where('id', $user->id)->update(['first_name' => implode(' ', $parts) ?: null, 'last_name' => $last]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['first_name', 'last_name', 'phone', 'phone_country', 'street', 'address_extra', 'postal_code', 'city', 'country', 'preferences']);
        });
    }
};
