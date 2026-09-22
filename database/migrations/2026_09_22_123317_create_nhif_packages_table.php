<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NHIF package/benefit catalog — synced from the NHIF API (Phase 8, Step 68).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_packages', function (Blueprint $table) {
            $table->id();
            $table->string('nhif_package_code')->unique();
            $table->string('nhif_package_name');
            $table->string('scheme_id')->nullable();
            $table->string('scheme_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->longText('raw_data')->nullable();
            $table->timestamps();

            $table->index('nhif_package_code');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_packages');
    }
};
