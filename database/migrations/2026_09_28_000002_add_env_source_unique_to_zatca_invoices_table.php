<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Zatca\Support\SourceUniqueness;

/**
 * One sale, one tax document, whichever EGS signs it — blueprint M-P2, phase P19.
 *
 * zatca_invoices_source_unique covers (egs_identifier, environment, source_reference),
 * which with a single EGS already means one document per sale. A second (B2B) EGS would
 * let the same sale be documented twice; this index forbids it. Separate from M-P1 so
 * either can be rolled back alone.
 *
 * Refuses to run while such duplicates exist (they cannot with one EGS): run
 * `php artisan zatca:check-source-uniqueness` first.
 */
return new class extends Migration
{
    public function up(): void
    {
        $groups = SourceUniqueness::duplicateGroups(Schema::getConnection());

        if ($groups !== []) {
            throw new RuntimeException(count($groups).' sale(s) already have ZATCA documents on more than one EGS; run `php artisan zatca:check-source-uniqueness` and resolve them first.');
        }

        Schema::table('zatca_invoices', function (Blueprint $table) {
            $table->unique(['environment', 'source_reference'], 'zatca_inv_env_src_uq');
        });
    }

    public function down(): void
    {
        Schema::table('zatca_invoices', function (Blueprint $table) {
            $table->dropUnique('zatca_inv_env_src_uq');
        });
    }
};
