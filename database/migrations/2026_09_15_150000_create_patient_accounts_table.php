<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A patient's financial account at one hospital (Phase 7, Step 52) — the
 * root of the billing domain: `Patient -> Patient Account -> Charge ->
 * Invoice -> Payment -> Receipt` (spec's own diagram). Scoped by
 * `hospital_id` directly, mirroring `StockItem`'s own facility-scoping
 * precedent (Phase 6, Step 49) exactly: a patient treated at two hospitals
 * gets two separate accounts, each fully isolated, never one account
 * spanning hospitals. One account per (hospital_id, patient_id) — "avoid
 * creating a separate financial account for every individual service"
 * (spec §6) is satisfied by this single, reused account accumulating every
 * charge for that patient at that hospital, auto-provisioned on first use
 * (`PatientAccountService::findOrCreateForPatient()`), never created ahead
 * of time for a patient who has never been charged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->string('account_number')->unique();
            $table->string('status')->default('active');
            $table->string('currency');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['hospital_id', 'patient_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_accounts');
    }
};
