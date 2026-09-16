<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier/cash-desk session tracking (Phase 7, Step 59) — the lifecycle
 * record that brackets a cashier's working shift from OPEN to CLOSED.
 *
 * A session is opened with an opening_balance (the float of cash already
 * in the drawer before collections begin), progresses through payment
 * collection (tracked via the existing payments/refunds/reversals tables
 * by cashier_id + time-window, no cashier_session_id FK on those tables
 * — linking by identity + time keeps the financial tables independent of
 * the session concept and avoids a migration on already-seeded tables),
 * and is closed by computing expected_closing_balance from real records,
 * recording the actual_closing_balance, computing the variance, and
 * requiring a variance_reason when the variance is non-zero.
 *
 * No UNIQUE(hospital_id, cashier_id, status='open') constraint: MySQL does
 * not support partial/filtered indexes. The application guard in
 * CashierSessionService::open() acquires a lockForUpdate on the set of
 * open sessions for this cashier+hospital before inserting, providing
 * serialisation at the same level every other financial transition uses.
 *
 * expected_closing_balance, actual_closing_balance, variance, variance_reason,
 * and closed_at are all null until the session is actually closed — they
 * are computed and recorded atomically by CashierSessionService::close().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_number')->unique();
            $table->foreignId('hospital_id')->constrained()->restrictOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->decimal('opening_balance', 10, 2)->default(0);
            $table->decimal('expected_closing_balance', 10, 2)->nullable();
            $table->decimal('actual_closing_balance', 10, 2)->nullable();
            $table->decimal('variance', 10, 2)->nullable();
            $table->text('variance_reason')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['hospital_id', 'cashier_id', 'status'], 'cashier_sessions_hospital_cashier_status_idx');
            $table->index(['hospital_id', 'status'], 'cashier_sessions_hospital_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_sessions');
    }
};
