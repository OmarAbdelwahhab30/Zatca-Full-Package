<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the two facts about a submission attempt that cannot be derived after the
 * event: who triggered it, and the error code ZATCA returned.
 *
 * All three columns are nullable and nothing is backfilled. A NULL
 * triggered_by_type means the attempt came from the system (the first automatic
 * report, the scheduler, or `zatca:requeue`) — which is truthful for every row
 * that already exists. Historical attempts must never be given an invented admin
 * or an inferred error code.
 *
 * Purely additive: existing writes remain valid without change, and deployed code
 * that does not know about these columns keeps working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zatca_submission_attempts', function (Blueprint $table) {
            $table->string('triggered_by_type')->nullable()->after('outcome');
            $table->unsignedBigInteger('triggered_by_id')->nullable()->after('triggered_by_type');
            $table->string('error_code')->nullable()->after('http_status');
        });
    }

    public function down(): void
    {
        Schema::table('zatca_submission_attempts', function (Blueprint $table) {
            $table->dropColumn(['triggered_by_type', 'triggered_by_id', 'error_code']);
        });
    }
};
