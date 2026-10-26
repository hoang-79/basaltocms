<?php

declare(strict_types=1);

use Basalto\Core\FrontendAccounts\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TB 63: Vor- und Nachname getrennt im Kundenkonto. `name` bleibt als ganzer Name (Rechnungen, Stripe, Anzeige) und
     * wird beim Speichern aus beiden gebildet; bestehende Namen teilt der Schnitt am letzten Leerzeichen.
     */
    public function up(): void
    {
        Schema::table('customers', static function (Blueprint $table): void {
            $table->string('first_name', 80)->nullable()->after('name');
            $table->string('last_name', 80)->nullable()->after('first_name');
        });
        DB::table('customers')->whereNotNull('name')->orderBy('id')->chunkById(500, static function ($rows): void {
            foreach ($rows as $row) {
                [$first, $last] = Customer::splitName((string) $row->name);
                DB::table('customers')->where('id', $row->id)->update(['first_name' => $first, 'last_name' => $last]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', static function (Blueprint $table): void {
            $table->dropColumn(['first_name', 'last_name']);
        });
    }
};
