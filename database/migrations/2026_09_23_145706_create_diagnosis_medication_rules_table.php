<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnosis → medication safety rules (Phase 3/6 safety check spec §12):
 * a database-driven contraindication/caution matrix that MedicationSafetyService
 * checks at prescription-order creation time. Rules are populated by an
 * administrator via a future admin UI or seeder — none are seeded here because
 * clinical rule content must be reviewed and approved before use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnosis_medication_rules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('diagnosis_code_id')
                ->constrained('diagnosis_codes')
                ->cascadeOnDelete();

            $table->foreignId('medication_id')
                ->constrained('medications')
                ->cascadeOnDelete();

            /**
             * 'contraindication' — prescribing is clinically unsafe and
             * requires explicit justification from the doctor.
             * 'caution' — prescribing is possible but the prescriber should
             * be aware; also requires justification.
             */
            $table->string('rule_type'); // contraindication | caution

            /**
             * 'high' → dangerous combination (e.g. serotonin syndrome risk).
             * 'medium' → significant clinical concern.
             * 'low' → minor concern/monitoring advised.
             */
            $table->string('severity')->default('high'); // high | medium | low

            $table->text('description');

            /** Optional clinical source reference (e.g. BNF, WHO EML note). */
            $table->string('source')->nullable();

            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();

            $table->unique(['diagnosis_code_id', 'medication_id', 'rule_type'], 'diag_med_rule_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnosis_medication_rules');
    }
};
