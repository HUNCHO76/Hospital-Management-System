<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single medication within a PrescriptionOrder (Phase 6, Step 46, "one
 * prescription, many medications"). The medication's code/generic name/
 * strength/form/route are snapshotted at prescribing time (mirrors
 * radiology_order_items/laboratory_order_items exactly) so a later catalog
 * rename/deactivation never makes an existing prescription ambiguous.
 * `route` is the clinically prescribed administration route for this
 * medication on this order, distinct from `route_snapshot` (the catalog's
 * own default route at prescribing time).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescription_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prescription_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('medication_id')->constrained()->restrictOnDelete();
            $table->string('medication_code_snapshot');
            $table->string('medication_name_snapshot');
            $table->string('strength_snapshot')->nullable();
            $table->string('form_snapshot')->nullable();
            $table->string('route_snapshot')->nullable();
            $table->string('dose');
            $table->string('dose_unit')->nullable();
            $table->string('frequency');
            $table->string('route');
            $table->unsignedInteger('duration')->nullable();
            $table->string('duration_unit')->nullable();
            $table->unsignedInteger('quantity');
            $table->string('quantity_unit')->nullable();
            $table->text('instructions')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index('prescription_order_id');
            $table->index('medication_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_order_items');
    }
};
