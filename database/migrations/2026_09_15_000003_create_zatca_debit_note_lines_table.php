<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One ADDED line on a Debit Note.
 *
 * This is where a debit note line differs fundamentally from a credit note line,
 * and why this table is not a copy of zatca_credit_note_lines:
 *
 *  - A credit note line is a verbatim copy of an original invoice line, identified
 *    by `original_line_index`, and it CONSUMES that line — hence the unique
 *    `active_line_key` ledger column there.
 *  - A debit note line is a NEW charge. Nothing on the original is consumed, so
 *    there is no ledger column here at all, and multiple debit notes may exist
 *    against one invoice without interfering with each other or with credit notes.
 *
 * `original_line_index` is therefore nullable and purely informational:
 *  - Additional Course: null — the charge is for something that was never on the
 *    original invoice. `course_id` says which course it is.
 *  - Price Adjustment / VAT Adjustment: set, to record WHICH original line the
 *    adjustment relates to. It is a reference only. Nothing in this system ever
 *    edits, re-prices or re-snapshots the original line it points at.
 *  - Other: null.
 *
 * `course_id` is nullable and used by the Additional Course reason, so the host's
 * acceptance listener can create the access Transaction for the right course
 * without re-parsing a free-text description.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zatca_debit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('debit_note_id')->index();
            $table->unsignedBigInteger('original_zatca_invoice_id');
            // Nullable — see class docblock. An Additional Course line references no
            // original line at all.
            $table->unsignedInteger('original_line_index')->nullable();

            $table->string('description');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 14, 2);
            $table->decimal('vat_rate', 5, 2);
            $table->decimal('line_total_excl_vat', 14, 2);
            $table->decimal('vat_amount', 14, 2);
            $table->decimal('line_total_incl_vat', 14, 2);

            $table->unsignedBigInteger('course_id')->nullable()->index();

            $table->timestamps();

            $table->index(['original_zatca_invoice_id', 'original_line_index'], 'zatca_dn_lines_original_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zatca_debit_note_lines');
    }
};
