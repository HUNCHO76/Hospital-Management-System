<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hospital admission transfer records, including NHIF transfer submission state (Phase 8, Step 70).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_hospital_id')->nullable()->constrained('hospitals')->nullOnDelete();
            $table->foreignId('to_hospital_id')->nullable()->constrained('hospitals')->nullOnDelete();
            $table->string('from_ward_type')->nullable();
            $table->string('to_ward_type')->nullable();
            $table->string('from_room_type')->nullable();
            $table->string('to_room_type')->nullable();
            $table->text('reason')->nullable();
            $table->decimal('charges_per_day', 12, 2)->nullable();
            $table->string('practitioner_no', 60)->nullable();
            $table->text('practitioners_remarks')->nullable();
            $table->string('status', 30);
            $table->string('nhif_admission_no', 100)->nullable();
            $table->string('nhif_status', 30);
            $table->string('nhif_transfer_no', 100)->nullable();
            $table->integer('nhif_ward_type_id')->nullable();
            $table->integer('nhif_room_type_id')->nullable();
            $table->smallInteger('http_status')->nullable();
            $table->text('raw_request')->nullable();
            $table->text('raw_response')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['admission_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_transfers');
    }
};
