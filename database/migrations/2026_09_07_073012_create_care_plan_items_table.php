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
        Schema::create('care_plan_items', function (Blueprint $table) {
            $table->id();
            // Not unique(): a plan holds multiple items.
            $table->foreignId('care_plan_id')->constrained()->restrictOnDelete();
            // Which diagnosis this item addresses (spec §15) — nullable
            // since some items (general monitoring, non-diagnosis-specific
            // instructions) aren't tied to one.
            $table->foreignId('encounter_diagnosis_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type');
            $table->text('description');
            $table->string('priority')->nullable();
            $table->string('status')->default('planned');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('instructions')->nullable();

            // Referral-specific — only populated when type = referral. No
            // separate referrals table: every other referral field the spec
            // lists (reason, priority, instructions, date, status) already
            // exists generically above.
            $table->foreignId('referred_to_department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->foreignId('referred_to_practitioner_id')->nullable()->constrained('practitioners')->restrictOnDelete();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('corrected_at')->nullable();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('correction_reason')->nullable();

            $table->timestamps();

            $table->index('care_plan_id');
            $table->index('encounter_diagnosis_id');
            $table->index('type');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('care_plan_items');
    }
};
