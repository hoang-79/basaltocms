<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TB 13 Scheibe 2: Das Frontend-Konto gehört seit den Mitgliedern (M-23) dem Kern — Lizenzverkauf, Marktplatz und
     * Mitglieder teilen es. Die Tabellen behalten Namen und Schema aus dem Lizenzverkauf (TB 11 Scheibe 1): Lizenzen,
     * Bestellungen, Rechnungen, Abos (Cashier) und Autorenprofile zeigen mit `customer_id` darauf, und bestehende
     * Installationen haben sie schon. Darum legt der Kern sie nur an, wenn es sie noch nicht gibt; die Migration des
     * Lizenzverkaufs tut dasselbe. Der Zeitstempel liegt vor dem Lizenzverkauf, damit frische Installationen sie hier anlegen.
     */
    public function up(): void
    {
        // Stripe-Kennungen unterscheiden Gross- und Kleinschreibung — auf MySQL/MariaDB binäre Sortierung (Cashier)
        $binary = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        if (! Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $table) use ($binary): void {
                $table->id();
                $table->foreignId('site_id')->constrained()->cascadeOnDelete();
                $table->string('email', 254);
                $table->string('name', 120)->nullable();
                $table->string('company', 160)->nullable();
                $table->string('street', 120)->nullable();
                $table->string('postal_code', 16)->nullable();
                $table->string('city', 80)->nullable();
                $table->char('country', 2)->nullable();
                $table->string('locale', 10)->default('de');
                $table->rememberToken();
                $table->timestamp('last_login_at')->nullable();
                // Laravel Cashier (Lizenzverkauf, Marktplatz)
                $binary ? $table->string('stripe_id')->collation('utf8mb4_bin')->nullable()->index() : $table->string('stripe_id')->nullable()->index();
                $table->string('pm_type')->nullable();
                $table->string('pm_last_four', 4)->nullable();
                $table->timestamp('trial_ends_at')->nullable();
                $table->timestamps();

                $table->unique(['site_id', 'email']);
            });
        }

        if (! Schema::hasTable('customer_login_codes')) {
            Schema::create('customer_login_codes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->constrained()->cascadeOnDelete();
                $table->string('email', 254);
                $table->char('code_hash', 64);
                $table->timestamp('expires_at');
                $table->timestamp('used_at')->nullable();
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->char('ip_hash', 64)->nullable();
                $table->timestamps();

                $table->index(['site_id', 'email']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_login_codes');
        Schema::dropIfExists('customers');
    }
};
