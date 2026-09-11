<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single requested procedure within a RadiologyOrder (Phase 5, Step 36,
 * Part 9 — "one order, many procedures", e.g. Chest X-Ray + Abdominal
 * Ultrasound on one order). `procedure_code`/`procedure_name` are
 * snapshotted at order time (mirrors laboratory_order_items exactly) so a
 * later catalog rename/deactivation never makes an existing order
 * ambiguous.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radiology_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('radiology_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('radiology_procedure_id')->constrained()->restrictOnDelete();
            $table->string('procedure_code');
            $table->string('procedure_name');
            $table->text('clinical_note')->nullable();
            $table->string('status')->default('ordered');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index('radiology_order_id');
            $table->index('radiology_procedure_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radiology_order_items');
    }
};
