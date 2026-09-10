<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('laboratory_orders', function (Blueprint $table) {
            $table->id();
            // unique(): one laboratory order per care plan item, mirroring
            // prescriptions.care_plan_item_id — the parent item's own
            // clinical_encounter_id/patient chain is never duplicated here
            // (reachable via care_plan_item -> care_plan -> clinical_encounter).
            $table->foreignId('care_plan_item_id')->unique()->constrained()->restrictOnDelete();
            $table->string('order_number')->unique();
            $table->foreignId('laboratory_test_id')->constrained()->restrictOnDelete();
            $table->string('priority')->default('routine');
            $table->string('status')->default('ordered');
            $table->foreignId('ordered_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('ordered_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index('laboratory_test_id');
            $table->index('status');
            $table->index('priority');
            $table->index('ordered_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('laboratory_orders');
    }
};
