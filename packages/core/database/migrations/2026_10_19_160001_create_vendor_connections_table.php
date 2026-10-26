<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TB 15 Scheibe 4 (CR-004 P-06): die Verbindung dieser Installation mit dem Kundenkonto beim Hersteller — eine Zeile
     * je Installation (wie der Lizenzschlüssel, nicht je Site). Das Token ist mit dem APP_KEY verschlüsselt (Text, weil
     * der Chiffretext länger ist als das Token); `vendor_url` hält fest, für welchen Hersteller es gilt.
     */
    public function up(): void
    {
        Schema::create('vendor_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('vendor_url', 255);
            $table->string('email', 254);
            $table->string('customer_name', 160)->nullable();
            $table->string('host', 259);
            $table->text('token');
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_connections');
    }
};
