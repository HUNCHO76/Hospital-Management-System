<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doctor-ordered clinical procedures and surgical operations.
 * - procedure_orders: Clinical orders for procedures (bedside, minor/major theatre, endoscopy).
 * - surgical_cases: Operating theatre episodes with WHO Surgical Safety Checklist, intra-op surgical notes, and PACU / recovery monitoring.
 * - bedside_procedure_events: Ward-based procedures performed at the bedside.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procedure_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 50)->unique();
            $table->foreignId('admission_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('clinical_encounter_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('procedure_name', 255);
            $table->string('procedure_type', 40); // bedside_procedure, minor_theatre, major_theatre, endoscopy, other
            $table->string('urgency', 20)->default('elective'); // elective, urgent, emergency
            $table->text('clinical_indication');
            $table->foreignId('prescribed_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('scheduled_at')->nullable();
            $table->string('status', 30)->default('ordered'); // ordered, scheduled, in_progress, completed, cancelled
            $table->dateTime('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['admission_id', 'status']);
            $table->index(['patient_id', 'status']);
            $table->index('procedure_type');
        });

        Schema::create('surgical_cases', function (Blueprint $table) {
            $table->id();
            $table->string('case_number', 50)->unique();
            $table->foreignId('procedure_order_id')->constrained('procedure_orders')->cascadeOnDelete();
            $table->foreignId('admission_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->string('theatre_room', 100);
            $table->foreignId('lead_surgeon_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assistant_surgeon_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('anesthetist_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('scrub_nurse_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('circulating_nurse_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('anesthesia_type', 50); // general, spinal, epidural, regional_block, local, sedation

            // WHO Surgical Safety Checklist (Sign In / Time Out / Sign Out)
            $table->boolean('identity_confirmed')->default(false);
            $table->boolean('site_marked')->default(false);
            $table->boolean('consent_confirmed')->default(false);
            $table->boolean('anesthesia_safety_check_completed')->default(false);
            $table->boolean('pulse_oximeter_functioning')->default(false);
            $table->boolean('allergy_screened')->default(false);
            $table->boolean('difficult_airway_risk_assessed')->default(false);
            $table->boolean('blood_loss_risk_assessed')->default(false);
            $table->boolean('fasting_npo_hours_confirmed')->default(false);
            $table->foreignId('checklist_completed_by')->nullable()->constrained('users')->nullOnDelete();

            // Intra-Operative Surgical Record
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->string('pre_operative_diagnosis', 255)->nullable();
            $table->string('post_operative_diagnosis', 255)->nullable();
            $table->text('surgical_technique')->nullable();
            $table->text('intraoperative_findings')->nullable();
            $table->text('implants_used')->nullable();
            $table->text('drains_placed')->nullable();
            $table->boolean('swab_instrument_count_correct')->default(true);
            $table->text('specimens_sent_to_lab')->nullable();
            $table->unsignedInteger('estimated_blood_loss_ml')->default(0);
            $table->text('complications')->nullable();

            // PACU / Post-Anesthesia Recovery Room Monitoring
            $table->dateTime('recovery_started_at')->nullable();
            $table->dateTime('recovery_ended_at')->nullable();
            $table->unsignedTinyInteger('aldrete_score')->nullable(); // 0-10 recovery readiness score
            $table->text('post_operative_orders')->nullable();
            $table->dateTime('transferred_to_ward_at')->nullable();

            $table->string('status', 30)->default('booked'); // booked, in_theatre, recovery, completed, cancelled
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['admission_id', 'status']);
            $table->index(['procedure_order_id', 'status']);
        });

        Schema::create('bedside_procedure_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_order_id')->constrained('procedure_orders')->cascadeOnDelete();
            $table->foreignId('admission_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->dateTime('performed_at');
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->text('procedure_notes');
            $table->string('patient_tolerance', 50)->default('tolerated_well');
            $table->text('consumables_used')->nullable();
            $table->text('complications')->nullable();
            $table->timestamps();

            $table->index(['admission_id', 'performed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bedside_procedure_events');
        Schema::dropIfExists('surgical_cases');
        Schema::dropIfExists('procedure_orders');
    }
};
