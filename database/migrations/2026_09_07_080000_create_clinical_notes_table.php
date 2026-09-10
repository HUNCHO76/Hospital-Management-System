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
        Schema::create('clinical_notes', function (Blueprint $table) {
            $table->id();
            // Not unique(): many notes (progress, nursing, follow-up, etc.)
            // are expected per encounter, from potentially different authors.
            $table->foreignId('clinical_encounter_id')->constrained()->restrictOnDelete();
            // Denormalized from the encounter and cross-checked by the
            // service at creation — never trusted from the request — so a
            // patient's notes can be queried directly without joining
            // through clinical_encounters.
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->string('note_type');
            $table->text('note_content');
            $table->string('status')->default('draft');

            $table->timestamp('signed_at')->nullable();
            $table->foreignId('signed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('corrected_at')->nullable();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('correction_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestamps();

            $table->index('clinical_encounter_id');
            $table->index('patient_id');
            $table->index('author_id');
            $table->index('note_type');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clinical_notes');
    }
};
