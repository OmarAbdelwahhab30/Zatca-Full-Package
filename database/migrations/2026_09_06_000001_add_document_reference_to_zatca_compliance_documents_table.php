<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A simplified credit/debit note compliance check must bill against a
 * previously submitted simplified invoice (ZATCA's BillingReference
 * requirement). This column records that invoice's business `cbc:ID` (the
 * `source_reference` fed into its XML) so ComplianceOnboardingOrchestrator can
 * look it up later — the existing `uuid` column holds `cbc:UUID`, a different
 * value ZATCA does not accept in a BillingReference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zatca_compliance_documents', function (Blueprint $table) {
            $table->string('document_reference')->nullable()->after('document_type');
        });
    }

    public function down(): void
    {
        Schema::table('zatca_compliance_documents', function (Blueprint $table) {
            $table->dropColumn('document_reference');
        });
    }
};
