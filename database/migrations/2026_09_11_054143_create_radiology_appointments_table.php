<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Radiology scheduling (Phase 5, Step 39). Deliberately its own table
 * rather than a reuse of `appointments` (Phase 2): that model's scheduling
 * axis is a mandatory, non-nullable `practitioner_id` — a person's slot —
 * whereas radiology scheduling books a room/machine, a concept
 * `appointments` has no column for at all. Weakening `practitioner_id` to
 * nullable to shoehorn radiology in would touch every existing OPD
 * appointment invariant for no real gain, so this is the "genuine domain
 * problem" the spec's own reuse-first instruction excepts.
 *
 * Scheduling is order-item-level (Part 3): one radiology order can contain
 * several procedures needing different times/rooms, so this references
 * `radiology_order_items`, not `radiology_orders` directly — patient,
 * encounter, procedure, and order are all reached transitively through the
 * item, exactly like every other Radiology table in this app (no
 * duplicated patient_id/facility_id columns, Part 4).
 *
 * No Room/Equipment model exists anywhere in this codebase (audited before
 * writing this migration) and building one is explicitly out of scope
 * (Part 8) — `resource_name` is a plain, required label (e.g. "X-Ray Room
 * 1"), the minimum structure the spec's own Part 6/9 escape hatch allows
 * absent a real equipment registry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radiology_appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('radiology_order_item_id')->constrained()->restrictOnDelete();
            // Copied from the item's own modality_snapshot at schedule time
            // — never user-editable — so the resource picker always shows
            // staff the true modality being booked, per Part 9's safest-
            // available validation absent a modality-to-room registry.
            $table->string('modality')->nullable();
            $table->string('resource_name');
            $table->dateTime('scheduled_start');
            $table->dateTime('scheduled_end');
            $table->string('status')->default('scheduled');
            $table->text('notes')->nullable();
            $table->foreignId('scheduled_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('rescheduled_at')->nullable();
            $table->text('reschedule_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('scheduled_start');
            $table->index('modality');
            $table->index(['resource_name', 'scheduled_start']);

            // Bulletproof double-booking guard for the exact scenario Part
            // 29/38 test explicitly requires (two schedulers racing for the
            // identical resource+start-time), including the classic
            // "first booking of the day for this resource" phantom-read
            // race that a plain lock-existing-rows check alone cannot catch
            // (there is nothing yet to lock). NULL for any non-active
            // appointment so cancelled/no-show history never blocks a real
            // rebooking of that same slot — both MySQL and SQLite unique
            // indexes treat multiple NULLs as distinct. This is the
            // "database constraint" layer; the interval-overlap case
            // (different but overlapping times) is additionally guarded at
            // the application level in RadiologySchedulingService via
            // lock-then-check, mirroring AppointmentService's own
            // established pattern. Concatenation syntax differs by driver
            // (MySQL's CONCAT() vs SQLite's ||, the latter needed since the
            // test suite runs on an in-memory SQLite connection) — both
            // support indexable generated columns.
            $concatExpression = DB::connection()->getDriverName() === 'sqlite'
                ? "resource_name || '|' || scheduled_start"
                : "concat(resource_name, '|', scheduled_start)";

            $table->string('active_slot_key')->nullable()->virtualAs(
                "case when status in ('scheduled','confirmed') then {$concatExpression} else null end"
            );
            $table->unique('active_slot_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radiology_appointments');
    }
};
