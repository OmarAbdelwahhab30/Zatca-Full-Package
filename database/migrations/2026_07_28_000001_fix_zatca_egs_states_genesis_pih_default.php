<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The original migration defaulted previous_invoice_hash to base64("0"),
 * but ZATCA/the SDK's own genesis convention is base64(hex(sha256("0")))
 * — confirmed by decoding the SDK's bundled default Data/PIH/pih.txt.
 * A raw base64("0") genesis value fails the SDK's local [PIH] validation
 * check (KSA-13) even though it never reaches ZATCA's servers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zatca_egs_states', function (Blueprint $table) {
            $table->string('previous_invoice_hash')->default(base64_encode(hash('sha256', '0')))->change();
        });
    }

    public function down(): void
    {
        Schema::table('zatca_egs_states', function (Blueprint $table) {
            $table->string('previous_invoice_hash')->default(base64_encode('0'))->change();
        });
    }
};
