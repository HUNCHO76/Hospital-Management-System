<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NHIF service catalog — items synced from the NHIF API (Phase 8, Step 68).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_services', function (Blueprint $table) {
            $table->id();
            $table->string('nhif_item_code')->unique();
            $table->string('nhif_item_name');
            $table->string('nhif_item_type_id')->nullable();
            $table->string('nhif_item_type_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->longText('raw_data')->nullable();
            $table->timestamps();

            $table->index('nhif_item_code');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_services');
    }
};
