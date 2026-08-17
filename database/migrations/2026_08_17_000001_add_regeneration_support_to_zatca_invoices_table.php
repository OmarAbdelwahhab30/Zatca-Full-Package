<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds what a ZATCA document needs in order to be regenerated: the business values
 * it was built from, and the links between a rejected document and its replacement.
 *
 * Two facts drive the shape here.
 *
 * First, `zatca_invoices` never stored the business snapshot. It keeps the totals and
 * the currency, but buyer name, buyer VAT number, issue date/time and the line items
 * lived only inside `unsigned_xml`. Regenerating a document from the original's values
 * — rather than re-reading the host Transaction/Order, which would let amounts drift —
 * requires those values in a form that can be handed back to the XML generator.
 * `invoice_data_snapshot` is that form: a serialized Zatca\DTOs\ZatcaInvoiceData.
 *
 * Second, a replacement cannot be expressed as a status. ZatcaInvoiceStatus is a
 * closed set that the reporting pipeline owns, and adding a value to it would change
 * how existing code classifies invoices. The relationship is therefore stored as two
 * explicit links, independent of status: `regenerated_from_id` on the replacement and
 * `superseded_by_id` on the original.
 *
 * Every column is nullable and nothing is backfilled. A NULL `invoice_data_snapshot`
 * is truthful for every row that already exists — those invoices genuinely have no
 * recorded snapshot, and reconstructing one by parsing their stored XML would be
 * inventing history. Such invoices are simply not regeneration-eligible. Purely
 * additive: existing writes stay valid and deployed code that does not know about
 * these columns keeps working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zatca_invoices', function (Blueprint $table) {
            // The business values this document was built from: buyer, issue date,
            // currency, line items and totals. Written at generation time from now on.
            // Business data only — never credentials, keys or certificate material.
            $table->json('invoice_data_snapshot')->nullable()->after('qr_code');

            // On a replacement: the document it was regenerated from.
            $table->unsignedBigInteger('regenerated_from_id')->nullable()->after('invoice_data_snapshot');

            // On an original: the replacement that took over from it. Written ONLY once
            // the replacement has been signed successfully, so a failed regeneration
            // never leaves an invoice pointing at a document that cannot be submitted.
            // This link — not a status — is what makes a document non-authoritative.
            $table->unsignedBigInteger('superseded_by_id')->nullable()->after('regenerated_from_id');

            // Set while a regeneration is in flight for this document. The claim is what
            // stops two admins (or an admin and the bulk action) from each producing a
            // replacement, which would consume two ICV slots for one invoice.
            $table->timestamp('regeneration_claimed_at')->nullable()->after('superseded_by_id');

            // Set on a replacement Draft whose unsigned XML no longer matches what the
            // current generator produces. Such a Draft must never be resumed: re-signing
            // it would re-emit the very document the regeneration is meant to replace.
            // Its reserved ICV stays consumed — ICV slots are never recycled.
            $table->timestamp('abandoned_at')->nullable()->after('regeneration_claimed_at');

            // The admin who asked for this regeneration. NULL means no human did, which
            // is truthful for every row that predates this column.
            $table->string('regenerated_by_type')->nullable()->after('abandoned_at');
            $table->unsignedBigInteger('regenerated_by_id')->nullable()->after('regenerated_by_type');

            $table->index('regenerated_from_id');
            $table->index('superseded_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('zatca_invoices', function (Blueprint $table) {
            $table->dropIndex(['regenerated_from_id']);
            $table->dropIndex(['superseded_by_id']);

            $table->dropColumn([
                'invoice_data_snapshot',
                'regenerated_from_id',
                'superseded_by_id',
                'regeneration_claimed_at',
                'abandoned_at',
                'regenerated_by_type',
                'regenerated_by_id',
            ]);
        });
    }
};
