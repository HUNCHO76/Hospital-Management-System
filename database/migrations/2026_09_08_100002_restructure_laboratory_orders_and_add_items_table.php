<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restructures `laboratory_orders` from a one-to-one detail record on a
 * `laboratory`-type CarePlanItem (Step 28) into a first-class entity
 * belonging directly to the ClinicalEncounter, with a new
 * `laboratory_order_items` child table supporting multiple tests per order
 * (Step 30 — spec's explicit domain model: "Do NOT make Prescription ->
 * Laboratory"). This is pre-production seed/dev data only (no real
 * laboratory catalog exists yet per every prior step's own disclaimer), so
 * a safe backfill-then-drop is used rather than a destructive drop/recreate
 * — every existing order's meaning is preserved, not discarded.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('laboratory_orders', function (Blueprint $table) {
            $table->foreignId('clinical_encounter_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->text('clinical_indication')->nullable()->after('priority');
            $table->text('notes')->nullable()->after('clinical_indication');
            $table->foreignId('updated_by')->nullable()->after('ordered_at')->constrained('users')->restrictOnDelete();

            $table->index('clinical_encounter_id');
        });

        Schema::create('laboratory_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('laboratory_test_id')->constrained()->restrictOnDelete();
            // Snapshotted at order time (spec §44) — an old order must never
            // become ambiguous if the catalog test is later renamed.
            $table->string('test_code');
            $table->string('test_name');
            $table->text('clinical_note')->nullable();
            $table->string('status')->default('ordered');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index('laboratory_order_id');
            $table->index('laboratory_test_id');
            $table->index('status');
        });

        // Backfill: every existing order today has exactly one test
        // (Step 28's 1-order-1-test shape), so this maps losslessly —
        // clinical_encounter_id is derived from the existing
        // care_plan_item -> care_plan -> clinical_encounter chain, and one
        // laboratory_order_items row is created per order carrying its
        // current laboratory_test_id/status. Raw query-builder calls, not
        // Eloquent, so this migration never depends on model state that may
        // change later.
        $orders = DB::table('laboratory_orders')
            ->join('care_plan_items', 'laboratory_orders.care_plan_item_id', '=', 'care_plan_items.id')
            ->join('care_plans', 'care_plan_items.care_plan_id', '=', 'care_plans.id')
            ->select(
                'laboratory_orders.id as order_id',
                'laboratory_orders.laboratory_test_id',
                'laboratory_orders.status',
                'laboratory_orders.cancelled_at',
                'laboratory_orders.cancelled_by',
                'laboratory_orders.cancellation_reason',
                'laboratory_orders.created_at',
                'laboratory_orders.updated_at',
                'care_plans.clinical_encounter_id',
            )
            ->get();

        foreach ($orders as $order) {
            $test = DB::table('laboratory_tests')->where('id', $order->laboratory_test_id)->first(['code', 'name']);

            DB::table('laboratory_orders')->where('id', $order->order_id)->update([
                'clinical_encounter_id' => $order->clinical_encounter_id,
            ]);

            DB::table('laboratory_order_items')->insert([
                'laboratory_order_id' => $order->order_id,
                'laboratory_test_id' => $order->laboratory_test_id,
                'test_code' => $test->code ?? 'UNKNOWN',
                'test_name' => $test->name ?? 'Unknown test',
                'status' => $order->status === 'cancelled' ? 'cancelled' : 'ordered',
                'cancelled_at' => $order->cancelled_at,
                'cancelled_by' => $order->cancelled_by,
                'cancellation_reason' => $order->cancellation_reason,
                'created_at' => $order->created_at,
                'updated_at' => $order->updated_at,
            ]);
        }

        Schema::table('laboratory_orders', function (Blueprint $table) {
            $table->dropForeign(['laboratory_test_id']);
            $table->dropIndex(['laboratory_test_id']);
            $table->dropColumn('laboratory_test_id');

            $table->dropForeign(['care_plan_item_id']);
            $table->dropUnique(['care_plan_item_id']);
            $table->dropColumn('care_plan_item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('laboratory_orders', function (Blueprint $table) {
            $table->foreignId('care_plan_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('laboratory_test_id')->nullable()->constrained()->restrictOnDelete();
        });

        Schema::dropIfExists('laboratory_order_items');

        Schema::table('laboratory_orders', function (Blueprint $table) {
            $table->dropForeign(['clinical_encounter_id']);
            $table->dropIndex(['clinical_encounter_id']);
            $table->dropColumn(['clinical_encounter_id', 'clinical_indication', 'notes', 'updated_by']);
        });
    }
};
