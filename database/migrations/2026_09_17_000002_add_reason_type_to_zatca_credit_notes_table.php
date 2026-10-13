<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a Credit Note was issued: 'item_cancellation' or 'price_adjustment' — see
 * Zatca\Enums\ZatcaCreditNoteReason.
 *
 * Nullable with no backfill: every note issued before this column existed was an
 * item cancellation, and ZatcaCreditNote::reason() reads NULL exactly that way, so
 * no existing row needs rewriting.
 *
 * A price adjustment's line rows need no schema change. They use
 * source_type = 'price_adjustment' and an active_line_key of "pa:{credit_note_id}",
 * which cannot collide with either existing key form, so the same unique index and
 * the same cancel mechanism (nulling the key) cover them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zatca_credit_notes', function (Blueprint $table) {
            $table->string('reason_type', 32)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('zatca_credit_notes', function (Blueprint $table) {
            $table->dropColumn('reason_type');
        });
    }
};
