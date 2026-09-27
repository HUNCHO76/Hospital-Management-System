<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only NHIF practitioner attendance (login/logout) history
 * (Phase 8, Step 67). Each record is immutable once created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_practitioner_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('practitioner_id')->constrained()->restrictOnDelete();
            $table->string('operation'); // login | logout
            $table->string('status'); // success | failed
            $table->json('request_data')->nullable();
            $table->json('response_data')->nullable();
            $table->timestamp('recorded_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['practitioner_id', 'operation', 'status'], 'nhif_pract_attend_op_status_idx');
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_practitioner_attendances');
    }
};
