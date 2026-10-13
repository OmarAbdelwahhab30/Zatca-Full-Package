<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A credited line may now come from an ACCEPTED Debit Note line as well as from the
 * original invoice's own lines.
 *
 *  - `source_type`: 'invoice_line' (every row before this migration, via the default)
 *    or 'debit_note_line'.
 *  - `source_debit_note_id` / `source_debit_note_line_id`: set only for a debit note
 *    line source, so acceptance can find the exact Transaction that note granted.
 *  - `original_line_index` becomes nullable: a debit note line is not a line of the
 *    original invoice, and giving it a fake index would make it collide with one.
 *
 * `active_line_key` is unchanged. Invoice lines keep "{invoice_id}:{index}"; debit
 * note lines use "dn_line:{debit_note_line_id}", which cannot collide with it, so the
 * same unique index guards both kinds and no existing row needs rewriting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zatca_credit_note_lines', function (Blueprint $table) {
            $table->string('source_type', 32)->default('invoice_line')->after('original_line_index');
            $table->unsignedBigInteger('source_debit_note_id')->nullable()->after('source_type');
            $table->unsignedBigInteger('source_debit_note_line_id')->nullable()->after('source_debit_note_id');

            $table->index('source_debit_note_id');
            $table->index('source_debit_note_line_id');
        });

        Schema::table('zatca_credit_note_lines', function (Blueprint $table) {
            $table->unsignedInteger('original_line_index')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('zatca_credit_note_lines', function (Blueprint $table) {
            $table->dropIndex(['source_debit_note_id']);
            $table->dropIndex(['source_debit_note_line_id']);
            $table->dropColumn(['source_type', 'source_debit_note_id', 'source_debit_note_line_id']);
        });

        // original_line_index deliberately stays nullable: debit-note-sourced rows have
        // no index to fall back to, and a rollback must neither fail on them nor delete them.
    }
};
