<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single billable line item against a PatientAccount (Phase 7, Step 52) —
 * what the patient account is being charged for, entirely separate from
 * "what the hospital provides" (no `Service`/catalog table exists yet; Step
 * 53 owns that) and from "money actually received" (`Payment`, a future
 * step's table). Spec §12/§13's own charge shape.
 *
 * No `service_id` FK: this codebase has no unified service catalog yet —
 * each clinical module owns its own priced catalog (`Medication.price`,
 * `LaboratoryTest.price`, `RadiologyProcedure.price`), and Step 53 ("Service
 * Catalog & Pricing") is the step that will actually unify them, not this
 * one. `source_type`/`source_id` is a plain descriptive pointer (never a
 * real FK — it spans several unrelated tables), mirroring `AuditLog`'s own
 * `auditable_type`/`auditable_id` and `StockTransaction`'s own
 * `reference_type`/`reference_id` precedent exactly (spec §12's own "safe
 * polymorphic/reference architecture" instruction). `description`/
 * `unit_price` are captured at charge-creation time rather than joined live
 * from a source record — the same snapshot precedent every order-item table
 * in this app already uses (`PrescriptionOrderItem.medication_name_snapshot`,
 * `RadiologyOrderItem.procedure_code`, etc.) — so a later catalog price
 * change can never rewrite a historical charge.
 *
 * `patient_id`/`hospital_id` are denormalized directly onto the charge
 * (rather than read only through `patient_account_id`) purely for direct,
 * indexable querying — the account is still the sole owner relationship
 * (spec §6's diagram: Account -> Charges), this is not a second source of
 * truth for which account a charge belongs to.
 *
 * `cancelled_at`/`cancelled_by`/`cancellation_reason` serve both the VOIDED
 * and CANCELLED transitions (distinguished by `status` alone) rather than
 * two near-identical column sets — mirrors `PrescriptionOrderItem`'s own
 * single cancellation column set regardless of the specific reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained('clinical_encounters')->restrictOnDelete();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('gross_amount', 10, 2);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('net_amount', 10, 2);
            $table->string('currency');
            $table->string('status')->default('pending');
            $table->timestamp('charged_at');
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index('patient_account_id');
            $table->index('patient_id');
            $table->index('encounter_id');
            $table->index('status');
            $table->index('charged_at');
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charges');
    }
};
