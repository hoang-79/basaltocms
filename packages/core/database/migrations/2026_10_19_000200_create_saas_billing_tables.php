<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TB 12 Scheibe 2 (M-21): Abo und Rechnungen der SaaS.
     *
     * - `accounts` wird Billable (Laravel Cashier): `stripe_id`, `pm_type`, `pm_last_four` (`trial_ends_at` gibt es schon —
     *   die Testphase). `accounts.instance_target` (Ziel eines Planwechsels über die Grenze der Instanzen, gesetzt von
     *   dieser Scheibe) legt die Migration von Scheibe 4 an (`2026_10_19_000401_create_saas_exit_tables`).
     * - `account_subscriptions`/`account_subscription_items`: das Schema von Cashier, aber an `account_id` und in eigenen
     *   Tabellen — `subscriptions` gehört dem Lizenzmodul (an `customer_id`), siehe `CashierModels`.
     * - `account_invoices`: Rechnungen an Konten — Stripe-Abo (bezahlt, aus `invoice.paid`) und QR-Rechnung (offen bis
     *   bezahlt). Nummer fortlaufend je Jahr und Instanz mit Präfix der Instanz (S-2026-0001 auf «start»), Positionen, MwSt.
     *   in Basispunkten, Adresse als Momentaufnahme; `source` macht das Ausstellen idempotent (`qr:<konto>:<periode>`,
     *   `stripe:<rechnung>`). Rechnungen bleiben, wenn das Konto geht (Aufbewahrungspflicht): `account_id` wird null.
     */
    public function up(): void
    {
        // Stripe-Kennungen unterscheiden Gross- und Kleinschreibung — auf MySQL/MariaDB binäre Sortierung (wie Cashier)
        $binary = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
        $stripe = static fn (Blueprint $table, string $column) => $binary ? $table->string($column)->collation('utf8mb4_bin') : $table->string($column);

        Schema::table('accounts', function (Blueprint $table) use ($stripe): void {
            $stripe($table, 'stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
        });

        Schema::create('account_subscriptions', function (Blueprint $table) use ($stripe): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $stripe($table, 'stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'stripe_status']);
        });

        Schema::create('account_subscription_items', function (Blueprint $table) use ($stripe): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained('account_subscriptions')->cascadeOnDelete();
            $stripe($table, 'stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->string('meter_id')->nullable();
            $table->integer('quantity')->nullable();
            $table->string('meter_event_name')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'stripe_price']);
        });

        Schema::create('account_invoices', function (Blueprint $table) use ($stripe): void {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 24)->unique();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('sequence');
            $table->string('source', 120)->unique();
            $table->string('method', 10);
            $table->string('plan', 20);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->char('currency', 3);
            $table->json('lines');
            $table->json('billing');
            $table->string('locale', 10)->default('de');
            $table->unsignedInteger('net_amount');
            $table->unsignedInteger('vat_rate')->default(0);
            $table->unsignedInteger('vat_amount')->default(0);
            $table->unsignedInteger('total');
            $table->string('status', 20)->default('open');
            $table->string('payment_reference', 40)->nullable();
            $stripe($table, 'stripe_invoice_id')->nullable();
            $table->date('issued_at');
            $table->date('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['year', 'sequence']);
            $table->index(['account_id', 'issued_at']);
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_invoices');
        Schema::dropIfExists('account_subscription_items');
        Schema::dropIfExists('account_subscriptions');
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropIndex(['stripe_id']);
            $table->dropColumn(['stripe_id', 'pm_type', 'pm_last_four']);
        });
    }
};
