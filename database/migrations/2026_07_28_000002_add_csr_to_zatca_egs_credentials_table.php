<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zatca_egs_credentials', function (Blueprint $table) {
            // CSR is not secret (it's submitted to ZATCA as-is) — stored in the clear,
            // needed between `zatca:generate-credentials` and `zatca:onboard compliance`.
            $table->text('csr')->nullable()->after('certificate');
        });
    }

    public function down(): void
    {
        Schema::table('zatca_egs_credentials', function (Blueprint $table) {
            $table->dropColumn('csr');
        });
    }
};
