<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NHIF excluded services per facility — synced from NHIF price list exclusion data (Phase 8, Step 68).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_excluded_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->restrictOnDelete();
            $table->string('facility_code', 100);
            $table->string('nhif_item_code', 100);
            $table->string('nhif_item_name')->nullable();
            $table->string('reason')->nullable();
            $table->date('effective_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('synced_at');
            $table->longText('raw_data')->nullable();
            $table->timestamps();

            $table->index(['hospital_id', 'nhif_item_code', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_excluded_services');
    }
};
