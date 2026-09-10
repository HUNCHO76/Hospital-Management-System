<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The authoritative specimen entity (Phase 4, Step 31). Belongs to a
 * LaboratoryOrder and (denormalized, mirroring ClinicalNote's own
 * `patient_id` precedent) directly to the ClinicalEncounter/Patient, so
 * sample lists/IDOR checks never need a multi-hop join. One sample may
 * satisfy several LaboratoryOrderItems (see laboratory_sample_items); a
 * single order may produce several samples — see docs/laboratory.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_samples', function (Blueprint $table) {
            $table->id();
            $table->string('sample_number')->unique();
            $table->foreignId('laboratory_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('clinical_encounter_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();

            // Specimen type/container reuse the exact validated vocabulary
            // LaboratoryTest::SPECIMEN_TYPES/SPECIMEN_CONTAINERS already
            // defines (Step 29) — no new lookup table.
            $table->string('specimen_type');
            $table->string('container_type')->nullable();

            $table->string('status')->default('pending_collection');

            $table->string('collection_site')->nullable();
            $table->text('collection_notes')->nullable();
            $table->foreignId('collected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('collected_at')->nullable();

            $table->timestamp('labelled_at')->nullable();

            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at')->nullable();

            $table->string('rejection_reason')->nullable();
            $table->text('rejection_notes')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('rejected_at')->nullable();

            // Preserves the full specimen history (spec §34) — a rejected
            // sample is never mutated or reused, only ever superseded by a
            // brand-new recollection row pointing back to it.
            $table->foreignId('recollection_of_sample_id')->nullable()->constrained('laboratory_samples')->restrictOnDelete();

            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestamps();

            $table->index('laboratory_order_id');
            $table->index('clinical_encounter_id');
            $table->index('patient_id');
            $table->index('status');
            $table->index('collected_at');
            $table->index('received_at');
            $table->index('specimen_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_samples');
    }
};
