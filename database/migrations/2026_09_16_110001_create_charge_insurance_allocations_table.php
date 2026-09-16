<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The financial responsibility split computed for one Charge (Phase 7, Step
 * 56, spec §9/§20/§27) — "of this charge's net amount, how much does the
 * insurer owe and how much does the patient owe." One row per charge
 * (`charge_id` UNIQUE): since `charges.invoice_id` already IS the invoice
 * line-item relationship (Step 55's own decision, never duplicated), a
 * charge's insurance allocation is naturally the item-level coverage spec
 * §22/§23 asks for — no separate "invoice item" concept was invented here
 * either.
 *
 * `hospital_id`/`patient_id`/`invoice_id` are denormalized from the charge
 * at calculation time (mirrors `Charge`'s own denormalized `hospital_id`/
 * `patient_id` off `PatientAccount`) purely for scoping/index performance —
 * the charge itself remains the single source of truth for them.
 *
 * This row is a historical financial snapshot (spec §20/§28): once the
 * invoice it belongs to is POSTED, `InsuranceBillingService` refuses to
 * change it again — a later change to `insurance_coverage_rules` must never
 * silently rewrite an already-finalized allocation. While the invoice is
 * still DRAFT, recalculation UPDATES this same row in place (audited as a
 * distinct action) rather than inserting a second row for the same charge —
 * the UNIQUE constraint on `charge_id` is what makes "no duplicate
 * allocation" (spec §27/§43) a database guarantee, not just an application
 * check.
 *
 * `insurance_coverage_rule_id` is nullable: a null value is the legitimate
 * "no rule matched, treated as not covered" outcome
 * (`CoverageRuleResolver`'s own docblock), not an error state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charge_insurance_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('charge_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('patient_insurance_id')->constrained()->restrictOnDelete();
            $table->foreignId('insurance_coverage_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('coverage_type_applied')->nullable();
            $table->decimal('coverage_percentage_applied', 5, 2)->nullable();
            $table->decimal('copay_amount_applied', 10, 2)->nullable();
            $table->decimal('eligible_amount', 10, 2);
            $table->decimal('covered_amount', 10, 2);
            $table->decimal('patient_responsibility', 10, 2);
            $table->string('currency');
            $table->string('status');
            $table->string('rejection_reason')->nullable();
            $table->timestamp('calculated_at');
            $table->foreignId('calculated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['hospital_id', 'invoice_id']);
            $table->index(['patient_id']);
            $table->index(['patient_insurance_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charge_insurance_allocations');
    }
};
