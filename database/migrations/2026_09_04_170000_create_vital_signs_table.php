<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vital_signs', function (Blueprint $table) {
            $table->id();
            // clinical_encounter_id is denormalized from triage_assessment_id
            // (every vital sign belongs to exactly one triage assessment,
            // which belongs to exactly one encounter) purely so encounter-
            // scoped queries/indexes don't need a join through
            // triage_assessments — never written independently of the
            // triage relation.
            $table->foreignId('clinical_encounter_id')->constrained()->restrictOnDelete();
            $table->foreignId('triage_assessment_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');

            // Canonical storage units (spec §9): °C, mmHg, bpm, breaths/min,
            // %, kg, cm. Any alternative unit accepted from a client is
            // converted server-side before this row is written — never
            // stored in a mixed/ambiguous unit.
            $table->decimal('temperature_c', 4, 1)->nullable();
            $table->unsignedSmallInteger('systolic_mmhg')->nullable();
            $table->unsignedSmallInteger('diastolic_mmhg')->nullable();
            $table->unsignedSmallInteger('pulse_bpm')->nullable();
            $table->unsignedSmallInteger('respiratory_rate_bpm')->nullable();
            $table->unsignedTinyInteger('oxygen_saturation_pct')->nullable();
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('height_cm', 5, 1)->nullable();
            // Server-calculated from weight_kg/height_cm — never accepted
            // directly from a client (spec §7).
            $table->decimal('bmi', 5, 2)->nullable();

            // Correction workflow (spec §19): an incorrect measurement is
            // never edited or deleted in place. A correction is a new row
            // pointing back at the row it supersedes; the original stays
            // exactly as originally recorded.
            $table->foreignId('corrects_vital_sign_id')->nullable()->constrained('vital_signs')->restrictOnDelete();
            $table->string('correction_reason')->nullable();

            $table->timestamps();

            // No separate index('clinical_encounter_id')/('triage_assessment_id')
            // — constrained() already causes an index on each FK column.
            $table->index('recorded_by');
            $table->index('recorded_at');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vital_signs');
    }
};
