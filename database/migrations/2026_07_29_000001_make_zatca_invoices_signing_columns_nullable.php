<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A "draft" invoice row is now created (with UUID/ICV/PIH/unsigned XML
 * already persisted) BEFORE signing runs, so a retry after an SDK failure
 * reuses the same reserved ICV/UUID instead of reserving a new one. That
 * means invoice_hash/signed_xml_base64/qr_code must be nullable until
 * signing actually succeeds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zatca_invoices', function (Blueprint $table) {
            $table->string('invoice_hash')->nullable()->change();
            $table->longText('signed_xml_base64')->nullable()->change();
            $table->longText('qr_code')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('zatca_invoices', function (Blueprint $table) {
            $table->string('invoice_hash')->nullable(false)->change();
            $table->longText('signed_xml_base64')->nullable(false)->change();
            $table->longText('qr_code')->nullable(false)->change();
        });
    }
};
