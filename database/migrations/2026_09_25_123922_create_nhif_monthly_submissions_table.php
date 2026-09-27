<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_monthly_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->restrictOnDelete();
            $table->string('facility_code');
            $table->unsignedSmallInteger('claim_year');
            $table->unsignedTinyInteger('claim_month');
            $table->unsignedInteger('folios_submitted')->default(0);
            $table->decimal('total_amount_claimed', 15, 2)->default(0);
            $table->text('submission_remarks')->nullable();
            $table->json('nhif_response')->nullable();
            $table->enum('status', ['pending', 'submitted', 'failed'])->default('pending');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['hospital_id', 'claim_year', 'claim_month'], 'nhif_monthly_unique_period');
            $table->index(['hospital_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_monthly_submissions');
    }
};
