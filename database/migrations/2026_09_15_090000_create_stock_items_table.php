<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A StockItem is "this Medication held at this hospital's pharmacy" (Phase 6,
 * Step 49) — pure identity plus reorder configuration, never batch-specific
 * data (quantity/expiry/cost all belong to StockBatch, spec §5). Scoped by
 * `hospital_id` directly, mirroring Staff/Department's own facility column,
 * not through Department: every hospital in this system has exactly one
 * 'PHARMACY' department (see DepartmentSeeder), so there is no existing
 * multi-location-pharmacy architecture to key off — introducing one here
 * would be unjustified over-engineering (spec §18/§21's own "do not
 * over-engineer" instruction). No `quantity` column here, deliberately: the
 * available/total quantity is always derived live by summing this item's
 * StockBatch rows, the same way DispensingService::remainingQuantity() is
 * always derived rather than stored (see docs/pharmacy.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->restrictOnDelete();
            $table->foreignId('medication_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('reorder_level')->default(0);
            $table->unsignedInteger('maximum_level')->nullable();
            $table->string('status')->default('active');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['hospital_id', 'medication_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_items');
    }
};
