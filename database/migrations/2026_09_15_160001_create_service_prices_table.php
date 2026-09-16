<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A service's price at one hospital, for one effective-dated period (Phase
 * 7, Step 53) — pricing lives entirely off the service definition itself
 * (spec §15), is always facility-specific (spec §16), and is never
 * overwritten (spec §17): a price change is always a *new* row with a new
 * `effective_from`, never an update to an existing row's `amount`. The
 * previous open-ended price's own `effective_to` is closed out
 * automatically by `ServicePricingService::create()` when a new price
 * supersedes it — see that service's own docblock.
 *
 * `status` (active/cancelled) is deliberately NOT "does today fall inside
 * effective_from/effective_to" — that is always computed live by
 * `ServicePriceResolver`, never stored. It exists only for the one scenario
 * effective dates alone cannot express: an administrator pulling a
 * mistakenly-entered *future* price before it ever takes effect (mirrors
 * `Charge`'s own PENDING-vs-VOIDED distinction, Step 52) — the row is never
 * deleted, only marked `cancelled` with a reason, so the mistake itself
 * stays auditable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->foreignId('hospital_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('currency');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status')->default('active');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['service_id', 'hospital_id', 'status']);
            $table->index('effective_from');
            $table->index('effective_to');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_prices');
    }
};
