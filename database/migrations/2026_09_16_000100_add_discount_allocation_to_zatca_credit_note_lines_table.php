<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records how much of a document-level allowance (today: a bundle discount) each
 * credited line consumed.
 *
 * This is the whole consumption ledger. It deliberately lives on the line rows
 * rather than in a table of its own, because a line's claim is released by
 * exactly one mechanism — CreditNoteCanceller nulling active_line_key — and
 * putting the consumed amount beside that column means a release frees the
 * discount with no second thing to keep in step.
 *
 * Null on every line that has no allowance behind it, which is every line of
 * every non-bundle invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zatca_credit_note_lines', function (Blueprint $table) {
            $table->string('discount_pool_key')->nullable()->after('line_total_incl_vat');
            $table->decimal('discount_applied_incl_vat', 15, 2)->nullable()->after('discount_pool_key');
        });
    }

    public function down(): void
    {
        Schema::table('zatca_credit_note_lines', function (Blueprint $table) {
            $table->dropColumn(['discount_pool_key', 'discount_applied_incl_vat']);
        });
    }
};
