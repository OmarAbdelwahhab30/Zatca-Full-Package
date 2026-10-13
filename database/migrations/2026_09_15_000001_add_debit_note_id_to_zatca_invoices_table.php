<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * debit_note_id links a Debit Note's technical zatca_invoices row to its business
 * record in zatca_debit_notes — the exact counterpart of credit_note_id, added by
 * 2026_09_14_000001, and for the same reasons.
 *
 * A separate nullable column rather than a polymorphic (adjustment_type,
 * adjustment_id) pair: the two note kinds have different business rules, different
 * eligibility and different listeners, and a nullable column each keeps every
 * existing credit-note query untouched. See the approved plan, decision 1.
 *
 * Deliberately not a DB-level foreign key — same convention as credit_note_id,
 * regenerated_from_id and superseded_by_id: SQLite rebuilds the whole table to
 * add/drop one, which this package's tests hit repeatedly.
 *
 * `document_type` already accepts 'simplified_debit_note' (the enum case and the
 * 383 generator both pre-date this feature, from compliance onboarding), so no
 * change is needed there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zatca_invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('debit_note_id')->nullable()->after('credit_note_id');

            $table->index('debit_note_id');
        });
    }

    public function down(): void
    {
        Schema::table('zatca_invoices', function (Blueprint $table) {
            $table->dropIndex(['debit_note_id']);
            $table->dropColumn('debit_note_id');
        });
    }
};
