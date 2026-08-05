<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zatca_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('egs_identifier')->index();
            $table->string('environment');
            // Host-defined idempotency key (e.g. "order:{$id}") — never generate twice for the same source.
            $table->string('source_reference');
            $table->string('owner_id')->nullable(); // OwnerResolverInterface result, nullable no-op default
            $table->string('uuid')->unique();
            $table->unsignedBigInteger('icv');
            $table->string('invoice_hash');
            $table->string('previous_invoice_hash');
            $table->longText('unsigned_xml');
            $table->longText('signed_xml_base64');
            $table->longText('qr_code');
            $table->decimal('total_excl_vat', 14, 2);
            $table->decimal('total_vat', 14, 2);
            $table->decimal('total_incl_vat', 14, 2);
            $table->string('currency', 3)->default('SAR');
            $table->string('status'); // ZatcaInvoiceStatus
            $table->string('reporting_status')->nullable(); // last known PASS/WARNING/ERROR
            $table->json('last_zatca_response')->nullable(); // sanitized
            $table->text('last_error')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();

            $table->unique(['egs_identifier', 'environment', 'source_reference'], 'zatca_invoices_source_unique');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zatca_invoices');
    }
};
