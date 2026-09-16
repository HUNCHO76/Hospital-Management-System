<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the existing `charges` table (Phase 7, Step 52) for automated
 * charge capture (Phase 7, Step 54) — this step FIXES AND EXTENDS the
 * already-authoritative `Charge`/`ChargeService`, never a second/duplicate
 * charge table (spec §1's own explicit instruction).
 *
 * `service_id` (nullable): links a charge back to its Step 53 catalog
 * `Service`, when one applies. Nullable because Pharmacy dispensing charges
 * deliberately do NOT go through the Service catalog at all — Step 53's own
 * documented boundary decision ("pharmacy pricing is stock/batch-driven, not
 * a generic-service concern") carries forward unchanged; a pharmacy charge
 * prices itself from `Medication.price` directly instead.
 *
 * `service_code_snapshot` (nullable): the resolved service's own `code` at
 * capture time — `description` (Step 52) already serves as the *name*
 * snapshot (repurposed here to always hold the resolved service/medication
 * name rather than free user text, see ChargeCaptureService's own
 * docblock), so only the code needed a new column, avoiding
 * `service_name_snapshot` as a redundant duplicate of `description`.
 *
 * `notes` (nullable): a new, genuinely distinct field from `description` —
 * free-text context/reason, required specifically for manual capture (spec
 * §26's "reason/reference"), optional everywhere else.
 *
 * `charge_number`: a stable, human-readable business reference (spec §38),
 * mirroring `Dispensing.dispensing_number`/`PatientAccount.account_number`'s
 * own `GeneratesBusinessNumber` precedent exactly. Nullable at the database
 * level only so this migration never has to synthesize numbers for
 * whatever pre-existing dev-only Step 52 rows happen to already exist; the
 * model generates one for every charge created from this point forward,
 * unconditionally.
 *
 * Duplicate-capture protection (spec §16/§25): the plain
 * `(source_type, source_id)` index Step 52 created is replaced here with a
 * UNIQUE constraint — MySQL/SQLite both treat multiple NULL,NULL rows as
 * distinct (so manual charges, which carry no source, are never
 * constrained against each other), while any real `(type, id)` pair can now
 * back at most one charge, database-enforced, not just
 * application-checked — the same `serviceable_type`/`serviceable_id`
 * uniqueness pattern `services` (Step 53) already established.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->string('charge_number')->nullable()->unique()->after('id');
            $table->foreignId('service_id')->nullable()->after('encounter_id')->constrained()->restrictOnDelete();
            $table->string('service_code_snapshot')->nullable()->after('source_id');
            $table->text('notes')->nullable()->after('description');

            $table->dropIndex(['source_type', 'source_id']);
        });

        Schema::table('charges', function (Blueprint $table) {
            $table->unique(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropUnique(['source_type', 'source_id']);
            $table->index(['source_type', 'source_id']);
            $table->dropConstrainedForeignId('service_id');
            $table->dropColumn(['charge_number', 'service_code_snapshot', 'notes']);
        });
    }
};
