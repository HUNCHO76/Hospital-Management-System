<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grows Step 44's minimal `medications` stub (id/name/category/status) into
 * the full authoritative catalog (Phase 6, Step 45) — mirrors exactly how
 * Step 37's own `add_configuration_fields_to_radiology_procedures_table`
 * grew `radiology_procedures` out of its own Step 36 stub.
 *
 * A plain drop-and-recreate rather than an additive `Schema::table()` +
 * rename, unlike that precedent: `name` needs to become `generic_name`
 * (spec §5) to make room for a distinct `brand_name`, and this server's
 * MariaDB version predates native `RENAME COLUMN` support, so recreating
 * the table avoids an unsupported/fragile ALTER. This is safe precisely
 * because Step 44 never wired any clinical write path to it — verified
 * before writing this migration: `Prescription::count()` is 0 and no
 * `Medication` row is referenced by any `prescriptions.medication_id` — so
 * every existing row is disposable seed/test data, not committed clinical
 * history (the historical-integrity rule this table exists to protect,
 * spec §11/§13, has nothing to protect yet).
 */
return new class extends Migration
{
    public function up(): void
    {
        // prescriptions.medication_id holds a restrict-on-delete FK into
        // this table, so it must be dropped before `medications` itself can
        // be dropped — restored on the new table immediately after.
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropForeign(['medication_id']);
        });

        Schema::dropIfExists('medications');

        Schema::create('medications', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('generic_name');
            $table->string('brand_name')->nullable();
            $table->string('strength')->nullable();
            $table->string('form')->nullable();
            $table->string('route')->nullable();
            $table->string('unit')->nullable();
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            // Billing/NHIF integration boundary only (spec §15/§16) —
            // mirrors laboratory_tests.nhif_code/price and
            // radiology_procedures.nhif_code/price exactly: preserved for a
            // future billing/claims module, neither read nor written by any
            // logic in this step.
            $table->string('nhif_code')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('status')->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('generic_name');
            $table->index('category');
            $table->index('form');
            $table->index('status');
        });

        Schema::table('prescriptions', function (Blueprint $table) {
            $table->foreign('medication_id')->references('id')->on('medications')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropForeign(['medication_id']);
        });

        Schema::dropIfExists('medications');

        // Recreates Step 44's original minimal stub (not this migration's
        // own full shape — this migration only grows the catalog forward)
        // purely so prescriptions.medication_id has a table to point back
        // at if this migration is ever rolled back.
        Schema::create('medications', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('category')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::table('prescriptions', function (Blueprint $table) {
            $table->foreign('medication_id')->references('id')->on('medications')->restrictOnDelete();
        });
    }
};
