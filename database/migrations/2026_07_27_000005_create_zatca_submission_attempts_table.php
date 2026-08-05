<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zatca_submission_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zatca_invoice_id')->constrained('zatca_invoices')->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('outcome'); // reported|reported_with_warnings|failed
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('sanitized_response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('attempted_at');
            $table->timestamps();

            $table->index(['zatca_invoice_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zatca_submission_attempts');
    }
};
