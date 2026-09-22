<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NHIF allowable tariff amounts per service mapping, effective-dated (Phase 8, Step 68).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_service_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nhif_service_mapping_id')->constrained()->restrictOnDelete();
            $table->decimal('allowable_amount', 12, 2);
            $table->char('currency', 3)->default('TZS');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->enum('status', ['active', 'superseded'])->default('active');
            $table->string('sync_reference', 100)->nullable();
            $table->longText('raw_data')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['nhif_service_mapping_id', 'status']);
            $table->index('effective_from');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_service_prices');
    }
};
