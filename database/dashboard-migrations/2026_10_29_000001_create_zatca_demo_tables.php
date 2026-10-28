<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sample sales data for the optional built-in dashboard's demo buttons.
 * Loaded only when ZATCA_DASHBOARD_ENABLED=true (see ZatcaServiceProvider).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zatca_demo_customers', function (Blueprint $t) {
            $t->id();
            $t->string('type', 10); // individual | company
            $t->string('name');
            $t->string('vat_number', 15)->nullable();
            $t->string('cr_number', 10)->nullable();
            $t->string('street')->nullable();
            $t->string('building_number', 4)->nullable();
            $t->string('additional_number', 4)->nullable();
            $t->string('district')->nullable();
            $t->string('city')->nullable();
            $t->string('postal_code', 5)->nullable();
            $t->string('country_code', 2)->default('SA');
            $t->timestamps();
        });

        Schema::create('zatca_demo_products', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->decimal('price_excl_vat', 12, 2);
            $t->decimal('vat_rate', 5, 2)->default(15);
            $t->timestamps();
        });

        Schema::create('zatca_demo_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('customer_id')->index();
            $t->string('type', 3); // b2c | b2b
            $t->dateTime('paid_at');
            $t->dateTime('supplied_at')->nullable();
            $t->timestamps();
        });

        Schema::create('zatca_demo_order_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id')->index();
            $t->string('name');
            $t->unsignedInteger('quantity');
            $t->decimal('price', 12, 2); // unit price as charged (VAT-inclusive for b2c, exclusive for b2b)
            $t->decimal('vat_rate', 5, 2)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['zatca_demo_order_items', 'zatca_demo_orders', 'zatca_demo_products', 'zatca_demo_customers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
