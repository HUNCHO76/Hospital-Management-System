<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The backend-configured coverage rule an insurance scheme applies to a
 * billable service (Phase 7, Step 56, spec §12/§13) — "how much of this
 * service does this insurance plan cover." Mirrors `service_prices`'
 * shape/lifecycle almost exactly (Phase 7, Step 53): effective-dated,
 * never edited in place, only created (a new version) or cancelled before
 * it ever takes effect. Global, not hospital-scoped — `InsuranceProvider`/
 * `InsuranceScheme` (Phase 2) and `Service` (Phase 7, Step 53) are both
 * already global catalogs, and nothing in this step's spec asks for a
 * facility-specific insurance contract.
 *
 * Three levels of specificity, most specific wins (resolved by
 * `CoverageRuleResolver`): `service_id` set = an exact-service override;
 * `category` set (one of `Service::CATEGORIES`, reused — never a second
 * category system) = a whole-category rule, the only way to configure
 * pharmacy coverage at all (pharmacy-sourced charges never carry a
 * `service_id` — see `ChargeCaptureService`'s own Pharmacy Boundary
 * docblock, Step 54); both null = the scheme's own default rule. At most
 * one of `service_id`/`category` may be set (validated in
 * `InsuranceCoverageRuleService`, not a DB constraint — simpler than a
 * database CHECK given every driver this project runs against).
 *
 * No rule matching a service at all is a legitimate, deliberate outcome —
 * `CoverageRuleResolver` returns null rather than throwing, and
 * `InsuranceBillingService` treats it as "not covered" (patient pays 100%),
 * never a hard-coded default percentage (spec §12's own "do not hard-code
 * rules... unless an explicit configured business rule").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurance_coverage_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insurance_scheme_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('category')->nullable();
            $table->string('coverage_type');
            $table->decimal('coverage_percentage', 5, 2)->nullable();
            $table->decimal('fixed_copay_amount', 10, 2)->nullable();
            $table->boolean('requires_preauthorization')->default(false);
            $table->string('status')->default('active');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['insurance_scheme_id', 'service_id', 'status'], 'insurance_coverage_rules_scheme_service_status_idx');
            $table->index(['insurance_scheme_id', 'category', 'status'], 'insurance_coverage_rules_scheme_category_status_idx');
            $table->index('effective_from');
            $table->index('effective_to');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_coverage_rules');
    }
};
