<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Line items within an NHIF claim, one per utilization record (Phase 8, Steps 72–73).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_claim_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nhif_claim_id')->constrained()->restrictOnDelete();
            $table->foreignId('nhif_utilization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('utilization_key')->nullable();
            $table->unsignedInteger('line_no');
            $table->foreignId('charge_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('nhif_service_mapping_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('nhif_preauthorization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('admission_id')->nullable()->constrained()->nullOnDelete();
            $table->string('module', 20);
            $table->string('description')->nullable();
            $table->string('nhif_item_code')->nullable();
            $table->string('nhif_item_name')->nullable();
            $table->string('nhif_package_code')->nullable();
            $table->string('nhif_authorization_no')->nullable();
            $table->date('service_date');
            $table->unsignedInteger('quantity');
            $table->decimal('hms_unit_price', 14, 2);
            $table->decimal('hms_amount', 14, 2);
            $table->decimal('nhif_allowable_amount', 14, 2)->nullable();
            $table->decimal('nhif_approved_amount', 14, 2)->nullable();
            $table->decimal('nhif_responsibility_amount', 14, 2)->nullable();
            $table->decimal('patient_responsibility_amount', 14, 2)->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('removal_reason')->nullable();
            $table->timestamps();

            $table->index(['nhif_claim_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_claim_items');
    }
};
