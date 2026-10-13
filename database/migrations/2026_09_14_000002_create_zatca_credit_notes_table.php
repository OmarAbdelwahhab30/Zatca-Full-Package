<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The business record behind a Credit Note: what was credited, why, and against
 * which original invoice — independent of the technical zatca_invoices row(s)
 * that carry its signed XML (one per generation/regeneration attempt).
 *
 * `original_zatca_invoice_id` is the authoritative document at the moment the
 * Credit Note was requested (the head of the original's supersede chain), not
 * necessarily the row an admin clicked from — see CreditNoteEligibilityPolicy.
 *
 * `accepted_at` is written once, inside the same DB transaction as the terminal
 * reporting outcome (ReportsZatcaInvoice), and is what "Credited" means
 * everywhere else in the system — see CreditNoteLedger. A note that is pending,
 * failed or rejected must never count as credited (BR-09).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zatca_credit_notes', function (Blueprint $table) {
            $table->id();
            // Nullable: assigned from the row's OWN id right after insert (CreditNoteIssuer),
            // still inside the same transaction — nothing outside it ever observes a null.
            // Nullable also lets MySQL's unique index tolerate more than one during that
            // instant if two inserts ever raced (it allows multiple NULLs).
            $table->string('credit_note_number')->nullable()->unique(); // "CN-000001"
            $table->string('egs_identifier')->index();
            $table->string('environment');

            $table->unsignedBigInteger('original_zatca_invoice_id')->index();
            $table->string('original_source_reference')->index(); // e.g. "payment:412" — root reference
            $table->string('billing_reference_id'); // original XML cbc:ID, frozen at issuance

            $table->string('type'); // full | partial
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
        Schema::dropIfExists('zatca_credit_notes');
    }
};
