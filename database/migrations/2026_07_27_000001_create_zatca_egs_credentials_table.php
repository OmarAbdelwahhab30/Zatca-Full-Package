<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zatca_egs_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('egs_identifier')->index();
            $table->string('environment'); // ZatcaEnvironment
            $table->string('credential_type'); // ZatcaCredentialType
            $table->text('binary_security_token')->nullable();
            $table->text('secret')->nullable(); // always encrypted at rest
            $table->text('request_id')->nullable(); // compliance_request_id, needed for production CSID
            $table->text('private_key')->nullable(); // encrypted PEM
            $table->text('certificate')->nullable(); // encrypted PEM
            $table->string('sdk_fingerprint')->nullable();
            $table->timestamp('certificate_issued_at')->nullable();
            $table->timestamp('certificate_expires_at')->nullable();
            $table->string('certificate_state')->default('valid'); // ZatcaCertificateState
            $table->timestamps();

            $table->unique(['egs_identifier', 'environment', 'credential_type'], 'zatca_egs_creds_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zatca_egs_credentials');
    }
};
