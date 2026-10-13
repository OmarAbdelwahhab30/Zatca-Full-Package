<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B2B (Standard, cleared) columns on zatca_invoices — blueprint M-P1, phase P18.
 *
 * Purely additive, and every default reproduces today's B2C meaning: submission_model
 * defaults to 'reporting' and everything else is null, so no existing row changes
 * meaning and no existing query changes result. Nothing writes these columns yet.
 *
 * No after(): appending lets MySQL add the columns with ALGORITHM=INSTANT instead of
 * rebuilding a large table.
 *
 * Rollback floor: down() is only valid while no clearance row exists. Once a Standard
 * document has been written, dropping these columns would destroy a cleared legal
 * document, so down() refuses (gate G-24).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zatca_invoices', function (Blueprint $table) {
            // reporting | clearance — written at reservation from the document type, never changed.
            $table->string('submission_model', 16)->default('reporting');
            // Raw ZATCA clearanceStatus (CLEARED / NOT_CLEARED), kept apart from reporting_status.
            $table->string('clearance_status', 32)->nullable();
            // The cleared document ZATCA returns — the legal copy of a Standard document.
            $table->longText('cleared_xml_base64')->nullable();
            $table->string('cleared_invoice_hash')->nullable();
            $table->timestamp('cleared_at')->nullable();
            // Per-document in-flight claim for a clearance HTTP call.
            $table->timestamp('submission_claimed_at')->nullable();
            // First timeout after sending: ZATCA may or may not have received it.
            $table->timestamp('in_doubt_since')->nullable();
            // buyer_correction for a B2B reissue (regeneration keeps null) and its actor.
            $table->string('reissue_reason', 32)->nullable();
            $table->string('reissued_by_type')->nullable();
            $table->unsignedBigInteger('reissued_by_id')->nullable();

            $table->index(['submission_model', 'status'], 'zatca_inv_model_status_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('zatca_invoices')->where('submission_model', 'clearance')->exists()) {
            throw new RuntimeException('Refusing to drop the B2B columns: zatca_invoices holds clearance documents (rollback floor, gate G-24).');
        }

        Schema::table('zatca_invoices', function (Blueprint $table) {
            $table->dropIndex('zatca_inv_model_status_idx');
            $table->dropColumn([
                'submission_model',
                'clearance_status',
                'cleared_xml_base64',
                'cleared_invoice_hash',
                'cleared_at',
                'submission_claimed_at',
                'in_doubt_since',
                'reissue_reason',
                'reissued_by_type',
                'reissued_by_id',
            ]);
        });
    }
};
