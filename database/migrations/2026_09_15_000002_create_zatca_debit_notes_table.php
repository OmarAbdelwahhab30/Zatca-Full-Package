<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The business record behind a Debit Note: what extra amount became due, why, and
 * against which original invoice — independent of the technical zatca_invoices
 * row(s) that carry its signed XML (one per generation/regeneration attempt).
 *
 * Mirrors zatca_credit_notes column-for-column except where the business genuinely
 * differs:
 *
 *  - There is no `type` (full/partial). A Credit Note consumes lines of the
 *    original and so must say how many; a Debit Note ADDS a charge and consumes
 *    nothing, so "full" and "partial" have no meaning here.
 *  - `reason_type` replaces it: the four approved reasons (additional_item,
 *    price_adjustment, vat_adjustment, other). `reason` keeps its Credit Note
 *    meaning — the KSA-10 / BR-KSA-17 free-text explanation that goes into the
 *    signed XML — and is mandatory for every reason type, not only `other`
 *    (ZATCA requires the text regardless; the host layer is what makes the
 *    admin type it by hand for `other` instead of deriving it).
 *
 * `accepted_at` is written once, inside the same DB transaction as the terminal
 * reporting outcome (ReportsZatcaInvoice), and is the ONLY thing that counts
 * financially. A note that is pending, failed or rejected is visible but never
 * counted — the same BR-09 rule Credit Notes follow.
 *
 * Critically, acceptance means ZATCA accepted the DOCUMENT. It does not mean the
 * customer has paid the extra amount; nothing here or downstream ever sets
 * transactions.is_paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zatca_debit_notes', function (Blueprint $table) {
            $table->id();
            // Nullable for the same reason as credit_note_number: assigned from the row's
            // OWN id right after insert (DebitNoteIssuer), still inside the same
            // transaction, so nothing outside it ever observes a null.
            $table->string('debit_note_number')->nullable()->unique(); // "DN-000001"
            $table->string('egs_identifier')->index();
            $table->string('environment');

            $table->unsignedBigInteger('original_zatca_invoice_id')->index();
            $table->string('original_source_reference')->index(); // e.g. "payment:412" — root reference
            $table->string('billing_reference_id'); // original XML cbc:ID, frozen at issuance

            $table->string('reason_type'); // additional_item | price_adjustment | vat_adjustment | other
            $table->text('reason'); // KSA-10 / BR-KSA-17, max 1000 chars enforced at request layer
            $table->json('document_snapshot'); // InvoiceDataSnapshot v2

            $table->string('currency', 3)->default('SAR');
            $table->decimal('total_excl_vat', 14, 2);
            $table->decimal('total_vat', 14, 2);
            $table->decimal('total_incl_vat', 14, 2);

            $table->decimal('original_total_incl_vat', 14, 2);
            $table->unsignedInteger('original_line_count');

            $table->uuid('idempotency_key')->unique();

            $table->string('requested_by_type')->nullable();
            $table->string('requested_by_id')->nullable();

            $table->timestamp('issued_at');
            $table->timestamp('accepted_at')->nullable()->index();
            $table->timestamp('generation_claimed_at')->nullable();
            $table->timestamp('generation_failed_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by_type')->nullable();
            $table->string('cancelled_by_id')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zatca_debit_notes');
    }
};
