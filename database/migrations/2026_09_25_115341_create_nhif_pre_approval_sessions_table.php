<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_pre_approval_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients');
            $table->foreignId('patient_insurance_id')->constrained('patient_insurances');
            $table->foreignId('hospital_id')->constrained('hospitals');
            $table->foreignId('clinical_encounter_id')->nullable()->constrained('clinical_encounters')->nullOnDelete();
            $table->string('nhif_authorization_no');
            $table->string('nhif_card_no')->nullable();
            $table->string('facility_code')->nullable();
            $table->string('practitioner_no')->nullable();
            $table->enum('status', [
                'draft',
                'submitted',
                'partially_approved',
                'approved',
                'rejected',
                'issued',
                'cancelled',
            ])->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->json('issue_payload')->nullable();
            $table->json('issue_response_payload')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_pre_approval_sessions');
    }
};
