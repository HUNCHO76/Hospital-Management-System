<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A radiology order — a clinical request placed directly against a
 * ClinicalEncounter for one or more procedures from the radiology catalog
 * (Phase 5, Step 36). Radiology is its own sibling branch off the
 * encounter, alongside Prescription/Laboratory/Diagnosis/Clinical Notes —
 * never nested under a CarePlanItem (Part 5) — built clean from the start,
 * unlike Laboratory which needed a Step 30 restructuring migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radiology_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinical_encounter_id')->constrained()->restrictOnDelete();
            $table->string('order_number')->unique();
            $table->string('priority')->default('routine');
            $table->text('clinical_indication')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('ordered');
            $table->foreignId('ordered_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('ordered_at');
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index('clinical_encounter_id');
            $table->index('status');
            $table->index('priority');
            $table->index('ordered_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radiology_orders');
    }
};
