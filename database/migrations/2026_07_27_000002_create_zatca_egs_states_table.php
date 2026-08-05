<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zatca_egs_states', function (Blueprint $table) {
            $table->id();
            $table->string('egs_identifier')->index();
            $table->string('environment');
            $table->string('state_type'); // ZatcaCredentialType: compliance|production — kept separate per plan
            $table->unsignedBigInteger('icv')->default(0); // Invoice Counter Value, monotonic per egs+environment+state_type
            // Genesis value ZATCA/the SDK expect for the first invoice in a chain:
            // base64(hex(sha256("0"))) — NOT base64("0").
            $table->string('previous_invoice_hash')->default(base64_encode(hash('sha256', '0')));
            $table->timestamps();

            $table->unique(['egs_identifier', 'environment', 'state_type'], 'zatca_egs_states_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zatca_egs_states');
    }
};
