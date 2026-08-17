<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Claim marker for an administrator-triggered completion of an unsigned Draft.
 *
 * A Draft is an invoice whose ICV, UUID, PIH and unsigned XML exist but whose signing
 * never finished. Signing it again is idempotent by construction — sign() locks the row
 * and returns it untouched unless it is still Draft — so this column is not what makes
 * the action safe. It exists so the interface can tell an administrator that the work is
 * already running, instead of showing an enabled button that quietly queues a job which
 * will do nothing.
 *
 * Additive and nullable: every existing row reads as unclaimed, so no deployed code path
 * changes behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zatca_invoices', function (Blueprint $table) {
            $table->timestamp('generation_claimed_at')->nullable()->after('regeneration_claimed_at');
        });
    }

    public function down(): void
    {
        Schema::table('zatca_invoices', function (Blueprint $table) {
            $table->dropColumn('generation_claimed_at');
        });
    }
};
