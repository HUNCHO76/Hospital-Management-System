<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_bill_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_insurance_id')->constrained()->restrictOnDelete();
            $table->string('nhif_authorization_no');
            $table->string('card_no')->nullable();
            $table->string('facility_code');
            $table->date('attendance_date');
            $table->decimal('total_amount', 15, 2);
            $table->string('confirmation_code')->nullable();
            $table->enum('confirmation_status', ['pending', 'confirmed', 'failed'])->default('pending');
            $table->json('nhif_response')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['hospital_id', 'nhif_authorization_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_bill_confirmations');
    }
};
