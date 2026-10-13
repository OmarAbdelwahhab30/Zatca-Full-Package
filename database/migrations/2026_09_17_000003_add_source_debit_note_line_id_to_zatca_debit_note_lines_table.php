<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which course an EARLIER Debit Note added this line adjusts.
 *
 * A Price Adjustment used to reference only a line of the original invoice
 * (`original_line_index`). A course added through an accepted Additional Course
 * Debit Note is never a line of the original, so it had no way to be referenced and
 * could not be price-adjusted at all. This is that reference: the id of the Debit
 * Note line that added the course — the same column name, and the same meaning,
 * zatca_credit_note_lines already uses for a debit note line.
 *
 * Informational only, exactly like `original_line_index`: nothing ever edits the
 * line it points at. A line sets at most one of the two references.
 *
 * Nullable with no backfill: every existing line references an original line or
 * nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zatca_debit_note_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('source_debit_note_line_id')->nullable()->after('original_line_index');
            $table->index('source_debit_note_line_id');
        });
    }

    public function down(): void
    {
        Schema::table('zatca_debit_note_lines', function (Blueprint $table) {
            $table->dropIndex(['source_debit_note_line_id']);
            $table->dropColumn('source_debit_note_line_id');
        });
    }
};
