<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zatca_compliance_documents', function (Blueprint $table) {
            $table->id();
            $table->string('egs_identifier')->index();
            $table->string('document_type'); // e.g. simplified_invoice, simplified_credit_note, standard_invoice
            $table->string('uuid')->unique();
            $table->unsignedBigInteger('icv');
            $table->string('invoice_hash');
            $table->string('previous_invoice_hash');
            $table->longText('unsigned_xml');
            $table->longText('signed_xml_base64');
            $table->string('reporting_status')->nullable(); // PASS / WARNING / ERROR
            $table->json('zatca_response')->nullable(); // sanitized
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zatca_compliance_documents');
    }
};
