<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One credited line, copied verbatim from the original invoice's frozen
 * snapshot at `original_line_index` — never recomputed from the host's
 * current Course/Transaction prices (BR-07, scenario 9: a discounted line's
 * post-discount net/VAT is what gets credited, unconditionally).
 *
 * `active_line_key` is the concurrency backstop for BR-06/BR-11: a unique,
 * nullable "{original_zatca_invoice_id}:{original_line_index}" that exists
 * exactly while the line is reserved or credited, and is cleared to NULL only
 * by an explicit Cancel Credit Note. Two requests racing to credit the same
 * line will have one succeed and one hit this unique index, even if both
 * passed the row-lock check (belt-and-braces alongside CreditNoteIssuer's
 * `SELECT ... FOR UPDATE`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zatca_credit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('credit_note_id')->index();
            $table->unsignedBigInteger('original_zatca_invoice_id');
            $table->unsignedInteger('original_line_index');

            $table->string('description');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 14, 2);
            $table->decimal('vat_rate', 5, 2);
            $table->decimal('line_total_excl_vat', 14, 2);
            $table->decimal('vat_amount', 14, 2);
            $table->decimal('line_total_incl_vat', 14, 2);

            $table->unsignedBigInteger('course_id')->nullable();

            $table->string('active_line_key')->nullable()->unique();

            $table->timestamps();

            $table->index(['original_zatca_invoice_id', 'original_line_index'], 'zatca_cn_lines_original_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zatca_credit_note_lines');
    }
};
