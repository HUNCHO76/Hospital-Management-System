<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NHIF admission submission state for an HMS admission record (Phase 8, Step 70).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_admissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_id')->constrained()->restrictOnDelete();
            $table->string('nhif_authorization_no', 100)->nullable();
            $table->string('nhif_facility_code', 60)->nullable();
            $table->string('nhif_practitioner_no', 60)->nullable();
            $table->integer('nhif_admission_type_id')->nullable();
            $table->integer('nhif_ward_type_id')->nullable();
            $table->integer('nhif_room_type_id')->nullable();
            $table->string('nhif_admission_no', 100)->nullable();
            $table->string('nhif_status', 30);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('raw_request')->nullable();
            $table->text('raw_response')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['admission_id', 'nhif_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_admissions');
    }
};
