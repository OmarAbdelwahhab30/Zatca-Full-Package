<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks every zatca_invoices row with the UBL document it actually is —
 * `simplified_invoice` (388) or `simplified_credit_note` (381). Needed before
 * any Credit Note row can exist: ProductionInvoiceOrchestrator's regeneration
 * and resume paths key XmlGeneratorFactory::forData() off this value via the
 * frozen snapshot, and the admin bulk/scheduled actions need to tell the two
 * apart.
 *
 * The default is truthful, not a guess: every row written before this column
 * existed went through the untyped generator path, which only ever produced
 * a 388 invoice.
 *
 * credit_note_id links a Credit Note's technical zatca_invoices row to its
 * business record in zatca_credit_notes (added separately). Deliberately not
 * a DB-level foreign key — same convention as regenerated_from_id and
 * superseded_by_id above, added for the same reason: SQLite rebuilds the
 * whole table to add/drop a foreign key, which this package's tests (which
 * migrate down between runs) hit repeatedly on real data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zatca_invoices', function (Blueprint $table) {
            $table->string('document_type')->default('simplified_invoice')->after('source_reference');
            $table->unsignedBigInteger('credit_note_id')->nullable()->after('document_type');

            $table->index('document_type');
            $table->index('credit_note_id');
        });
    }

    public function down(): void
    {
        Schema::table('zatca_invoices', function (Blueprint $table) {
            $table->dropIndex(['document_type']);
            $table->dropIndex(['credit_note_id']);
            $table->dropColumn(['document_type', 'credit_note_id']);
        });
    }
};
