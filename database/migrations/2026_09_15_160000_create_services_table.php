<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The central, authoritative billing service catalog (Phase 7, Step 53) —
 * "what the hospital can charge for," entirely separate from `Charge`
 * ("what a patient account is being charged", Step 52) and from each
 * clinical module's own operational catalog (`LaboratoryTest`,
 * `RadiologyProcedure`, `Medication`). Global/hospital-agnostic, exactly
 * like those three catalogs already are — see this migration's own
 * `serviceable_type`/`serviceable_id` note for why `department_id` is
 * deliberately NOT a column here.
 *
 * `serviceable_type`/`serviceable_id` is a plain descriptive polymorphic
 * pointer back to the clinical module row this billing service represents
 * (`LaboratoryTest`, `RadiologyProcedure`, ...) — nullable, since a
 * standalone service (e.g. a plain consultation, with no clinical-module
 * catalog to map to) is equally valid. Mirrors `Charge.source_type`/
 * `source_id` (Step 52) and `StockTransaction.reference_type`/
 * `reference_id` (Step 49) exactly — this codebase's own established,
 * repeated solution for "one entity may originate from any of several
 * unrelated tables," not polymorphism introduced for its own sake (spec
 * §28's own "do not introduce polymorphism simply because it is possible"
 * is satisfied by reusing an existing pattern, not inventing a new one).
 *
 * No `department_id`: `Department` is hospital-scoped (`departments.
 * hospital_id`), exactly the same contradiction `LaboratoryTestSeeder`'s own
 * migration-era decision already discovered and documented ("linking every
 * seeded test to one specific hospital's department would misrepresent
 * shared master data as belonging to only that hospital") — a *global*
 * Service has the identical problem. `category` is the reused, already-
 * proven-safe classification mechanism instead (mirrors
 * `LaboratoryTestCategory`'s own role, spec §8/§10's "reuse the existing...
 * do not duplicate department data" satisfied by not forcing a hospital-
 * specific FK onto global data).
 *
 * No separate `service_type` column: spec §8 (categories) and §9 (service
 * types) list near-identical vocabularies — mirrors
 * `RadiologyProcedure.modality`'s own precedent ("a single field
 * deliberately serves both... a second column would only duplicate the
 * same fact and risk drifting out of sync").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category');
            $table->string('serviceable_type')->nullable();
            $table->unsignedBigInteger('serviceable_id')->nullable();
            $table->boolean('is_billable')->default(true);
            $table->string('status')->default('active');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['serviceable_type', 'serviceable_id']);
            $table->index('category');
            $table->index('status');
            $table->index('is_billable');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
