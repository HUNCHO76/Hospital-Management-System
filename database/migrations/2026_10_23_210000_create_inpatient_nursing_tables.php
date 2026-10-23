<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inpatient nursing care additions. Everything here is additive — no
 * existing column is changed or dropped and no historical row is touched.
 *
 * - vital_signs: measured_at (when the measurement was taken, distinct from
 *   recorded_at, when it was entered) plus the extra bedside observations
 *   (pain, AVPU consciousness, oxygen support, intake/output, notes).
 * - clinical_notes: optional structured category for nursing notes.
 * - medication_administrations: slot_key, a nullable UNIQUE key that makes a
 *   second record for the same scheduled dose impossible at database level.
 * - observation_escalations, wound_care_events, nursing_handovers,
 *   patient_allergies: genuinely new clinical records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vital_signs', function (Blueprint $table) {
            $table->timestamp('measured_at')->nullable();
            $table->unsignedTinyInteger('pain_score')->nullable();
            $table->string('consciousness_level', 20)->nullable();
            $table->string('oxygen_support', 30)->nullable();
            $table->decimal('oxygen_flow_lpm', 4, 1)->nullable();
            $table->unsignedInteger('intake_ml')->nullable();
            $table->unsignedInteger('output_ml')->nullable();
            $table->text('observation_notes')->nullable();
        });

        Schema::table('clinical_notes', function (Blueprint $table) {
            $table->string('note_category', 40)->nullable();
        });

        Schema::table('medication_administrations', function (Blueprint $table) {
            $table->string('slot_key', 80)->nullable()->unique('mar_slot_key_unique');
            $table->text('allergy_override_reason')->nullable();
        });

        Schema::create('patient_allergies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->string('allergen', 150);
            $table->foreignId('medication_id')->nullable()->constrained()->nullOnDelete();
            $table->string('severity', 20);
            $table->string('reaction', 255)->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_reason')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'status']);
        });

        Schema::create('observation_escalations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('vital_sign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 20);
            $table->text('reason');
            $table->json('flags')->nullable();
            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('raised_at');
            $table->foreignId('notified_practitioner_id')->nullable()->constrained('practitioners')->nullOnDelete();
            $table->string('status', 20)->default('open');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['admission_id', 'status']);
            $table->index(['status', 'raised_at']);
        });

        Schema::create('wound_care_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->timestamp('performed_at');
            $table->string('site', 150);
            $table->text('indication');
            $table->text('assessment');
            $table->text('appearance')->nullable();
            $table->text('dressing_performed')->nullable();
            $table->text('materials_used')->nullable();
            $table->unsignedTinyInteger('pain_score')->nullable();
            $table->text('patient_response')->nullable();
            $table->text('complications')->nullable();
            $table->boolean('concerning_findings')->default(false);
            $table->timestamp('next_dressing_due_at')->nullable();
            $table->text('follow_up_instructions')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('corrects_id')->nullable()->constrained('wound_care_events')->nullOnDelete();
            $table->text('correction_reason')->nullable();
            $table->timestamps();

            $table->index(['admission_id', 'performed_at']);
            $table->index('next_dressing_due_at');
        });

        Schema::create('nursing_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->string('shift', 20);
            $table->timestamp('handover_at');
            $table->text('situation_summary');
            $table->text('pending_tasks')->nullable();
            $table->json('outstanding_doses')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('corrects_id')->nullable()->constrained('nursing_handovers')->nullOnDelete();
            $table->text('correction_reason')->nullable();
            $table->timestamps();

            $table->index(['admission_id', 'handover_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nursing_handovers');
        Schema::dropIfExists('wound_care_events');
        Schema::dropIfExists('observation_escalations');
        Schema::dropIfExists('patient_allergies');

        Schema::table('medication_administrations', function (Blueprint $table) {
            $table->dropUnique('mar_slot_key_unique');
            $table->dropColumn(['slot_key', 'allergy_override_reason']);
        });

        Schema::table('clinical_notes', function (Blueprint $table) {
            $table->dropColumn('note_category');
        });

        Schema::table('vital_signs', function (Blueprint $table) {
            $table->dropColumn([
                'measured_at', 'pain_score', 'consciousness_level', 'oxygen_support',
                'oxygen_flow_lpm', 'intake_ml', 'output_ml', 'observation_notes',
            ]);
        });
    }
};
