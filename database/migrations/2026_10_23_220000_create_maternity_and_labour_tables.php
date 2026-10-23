<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maternity and Labour monitoring tables:
 * - maternity_profiles: Antenatal context, parity, risk factors, and labour episode.
 * - partograph_entries: WHO-aligned partograph progress readings over time (dilation, fetal heart rate, descent, contractions, maternal vitals).
 * - delivery_records: Labour delivery summary, maternal outcome, and neonatal outcome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maternity_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('gravida')->default(1);
            $table->unsignedTinyInteger('para')->default(0);
            $table->unsignedTinyInteger('living_children')->default(0);
            $table->unsignedTinyInteger('abortions')->default(0);
            $table->date('last_menstrual_period')->nullable();
            $table->date('estimated_date_of_delivery')->nullable();
            $table->decimal('gestational_age_weeks', 4, 1)->nullable();
            $table->string('presentation', 50)->default('cephalic');
            $table->string('membrane_status', 30)->default('intact');
            $table->dateTime('membrane_ruptured_at')->nullable();
            $table->string('blood_group', 10)->nullable();
            $table->string('rhesus', 10)->nullable();
            $table->string('hiv_status', 20)->default('unknown');
            $table->string('vdrl_rpr_status', 20)->default('non_reactive');
            $table->json('risk_factors')->nullable();
            $table->string('labour_stage', 30)->default('latent_phase');
            $table->string('status', 20)->default('active');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['admission_id', 'status']);
            $table->index(['patient_id', 'status']);
        });

        Schema::create('partograph_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maternity_profile_id')->constrained('maternity_profiles')->cascadeOnDelete();
            $table->foreignId('admission_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->dateTime('recorded_at');

            // Fetal Wellbeing
            $table->unsignedSmallInteger('fetal_heart_rate')->nullable();
            $table->string('amniotic_fluid', 10)->nullable(); // I, C, M, B, A
            $table->string('moulding', 10)->nullable(); // 0, +, ++, +++

            // Labour Progress
            $table->decimal('cervical_dilation_cm', 3, 1)->nullable();
            $table->unsignedTinyInteger('descent_of_head')->nullable(); // 0 to 5 (fifths)
            $table->decimal('alert_line_dilation_cm', 3, 1)->nullable();
            $table->decimal('action_line_dilation_cm', 3, 1)->nullable();
            $table->boolean('action_line_crossed')->default(false);

            // Contractions
            $table->unsignedTinyInteger('contractions_per_10min')->nullable();
            $table->string('contraction_duration', 20)->nullable(); // mild, moderate, strong

            // Medication & Maternal Wellbeing
            $table->unsignedSmallInteger('oxytocin_drops_per_min')->nullable();
            $table->string('oxytocin_dose_units', 50)->nullable();
            $table->text('drugs_iv_fluids')->nullable();
            $table->unsignedSmallInteger('systolic_bp')->nullable();
            $table->unsignedSmallInteger('diastolic_bp')->nullable();
            $table->unsignedSmallInteger('pulse_bpm')->nullable();
            $table->decimal('temperature_c', 4, 1)->nullable();
            $table->string('urine_protein', 20)->nullable();
            $table->string('urine_acetone', 20)->nullable();
            $table->unsignedSmallInteger('urine_volume_ml')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['maternity_profile_id', 'recorded_at']);
        });

        Schema::create('delivery_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maternity_profile_id')->constrained('maternity_profiles')->cascadeOnDelete();
            $table->foreignId('admission_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->dateTime('delivered_at');
            $table->string('delivery_mode', 50); // spontaneous_vaginal, assisted_vacuum, assisted_forceps, elective_c_section, emergency_c_section
            $table->string('indication', 255)->nullable();
            $table->foreignId('conducted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assisted_by')->nullable()->constrained('users')->nullOnDelete();

            // Maternal Outcome
            $table->dateTime('placenta_delivered_at')->nullable();
            $table->string('placenta_mode', 40)->default('spontaneous');
            $table->boolean('placenta_complete')->default(true);
            $table->unsignedInteger('blood_loss_ml')->default(0);
            $table->boolean('postpartum_hemorrhage')->default(false);
            $table->string('perineal_status', 50)->default('intact');
            $table->string('suture_materials_used', 255)->nullable();
            $table->string('oxytocic_given', 100)->nullable();

            // Neonatal Outcome
            $table->string('baby_outcome', 30)->default('live_birth'); // live_birth, fresh_stillbirth, macerated_stillbirth
            $table->string('baby_gender', 20)->default('female');
            $table->unsignedSmallInteger('birth_weight_grams')->nullable();
            $table->decimal('birth_length_cm', 4, 1)->nullable();
            $table->decimal('head_circumference_cm', 4, 1)->nullable();
            $table->unsignedTinyInteger('apgar_1min')->nullable();
            $table->unsignedTinyInteger('apgar_5min')->nullable();
            $table->unsignedTinyInteger('apgar_10min')->nullable();
            $table->boolean('resuscitation_required')->default(false);
            $table->text('resuscitation_notes')->nullable();
            $table->boolean('vitamin_k_given')->default(true);
            $table->boolean('eye_ointment_given')->default(true);
            $table->boolean('bcg_given')->default(false);
            $table->boolean('polio_0_given')->default(false);
            $table->foreignId('baby_patient_id')->nullable()->constrained('patients')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['maternity_profile_id', 'delivered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_records');
        Schema::dropIfExists('partograph_entries');
        Schema::dropIfExists('maternity_profiles');
    }
};
