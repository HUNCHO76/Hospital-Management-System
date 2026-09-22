<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only log of all outbound NHIF API calls (Phase 8, Step 64).
 * NhifHttpClient writes one row per request to support audit and debugging.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_integration_logs', function (Blueprint $table) {
            $table->id();
            $table->string('operation', 100);
            $table->string('endpoint', 500);
            $table->string('http_method', 10);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->boolean('success');
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->longText('raw_response')->nullable();
            $table->string('correlation_id', 36)->nullable();
            $table->foreignId('facility_id')->nullable()->constrained('hospitals')->nullOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('responded_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['operation', 'success']);
            $table->index('requested_at');
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_integration_logs');
    }
};
