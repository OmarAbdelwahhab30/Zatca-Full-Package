<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail for a Credit Note's lifecycle (BR-15). Complements,
 * rather than duplicates, zatca_submission_attempts (transport-level attempts,
 * already audited) and regenerated_by_* on zatca_invoices — this table records
 * the business-level events those don't: requested, generation_failed,
 * generated, cancelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zatca_credit_note_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('credit_note_id')->index();
            $table->string('event'); // requested | generation_started | generation_failed | generated | cancelled | ...
            $table->string('actor_type')->nullable();
            $table->string('actor_id')->nullable();
            $table->json('detail')->nullable();
            $table->timestamp('created_at');

            $table->index(['credit_note_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zatca_credit_note_events');
    }
};
