<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HMS service → NHIF item code mapping, per-hospital (Phase 8, Step 68).
 * Effective-dated history; deactivation is preferred over deletion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_service_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('hospital_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nhif_item_code', 100);
            $table->string('nhif_item_name');
            $table->string('nhif_package_code', 100)->nullable();
            $table->string('nhif_package_name')->nullable();
            $table->enum('coverage_status', ['covered', 'partially_covered', 'excluded', 'not_mapped', 'unknown'])
                ->default('covered');
            $table->boolean('requires_preauth')->default(false);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->enum('mapping_source', ['manual', 'sync'])->default('manual');
            $table->timestamp('last_synced_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['service_id', 'hospital_id', 'is_active']);
            $table->index('nhif_item_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_service_mappings');
    }
};
