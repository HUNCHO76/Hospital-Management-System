<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NHIF price/catalog synchronization history (Phase 8, Step 68).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_price_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->nullable()->constrained()->nullOnDelete();
            $table->string('facility_code', 100)->nullable();
            $table->enum('sync_type', ['price_list', 'excluded_services', 'package_with_exclusions', 'items', 'packages']);
            $table->string('type_name', 100)->nullable();
            $table->string('facility_level', 100)->nullable();
            $table->enum('status', ['started', 'completed', 'failed', 'partial']);
            $table->unsignedInteger('total_records')->nullable();
            $table->unsignedInteger('new_records')->nullable();
            $table->unsignedInteger('updated_records')->nullable();
            $table->unsignedInteger('skipped_records')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['hospital_id', 'sync_type', 'status']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_price_sync_logs');
    }
};
