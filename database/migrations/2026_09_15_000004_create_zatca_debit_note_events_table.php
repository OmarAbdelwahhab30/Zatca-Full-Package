<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail for a Debit Note's lifecycle. Exact counterpart of
 * zatca_credit_note_events: complements, rather than duplicates,
 * zatca_submission_attempts (transport-level) and regenerated_by_* on
 * zatca_invoices, by recording the business-level events those do not —
 * requested, generation_failed, generated, cancelled, access_granted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zatca_debit_note_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('debit_note_id')->index();
            $table->string('event'); // requested | generation_started | generation_failed | generated | cancelled | ...
            $table->string('actor_type')->nullable();
            $table->string('actor_id')->nullable();
            $table->json('detail')->nullable();
            $table->timestamp('created_at');

            $table->index(['debit_note_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zatca_debit_note_events');
    }
};
