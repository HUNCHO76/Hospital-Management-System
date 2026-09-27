<?php

use App\Models\Service;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Facility-wide fallback consultation service (payment-gating audit
 * follow-up): when a practitioner has no practitioner-specific
 * `consultation_service_id`, ChargeCaptureService now falls back to the
 * assigned hospital's `default_consultation_service_id` rather than
 * treating a missing configuration as "no charge" (a missing configuration
 * must never implicitly mean free). NULL is still a valid state — the
 * true no-charge safety net only applies when neither resolves.
 *
 * The data-fix below points the seeded MAIN hospital at the existing
 * "General Consultation" (CONSULT-GP) catalog service, looked up by code
 * rather than a hardcoded id, and only when the column is still unset —
 * safe to rerun. No price is created here; an administrator must still
 * enter a real amount via the Service Pricing screen before this actually
 * raises a bill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hospitals', function (Blueprint $table) {
            $table->foreignId('default_consultation_service_id')->nullable()->after('status')
                ->constrained('services')->nullOnDelete();
        });

        $consultGpId = DB::table('services')->where('code', 'CONSULT-GP')->value('id');

        if ($consultGpId !== null) {
            DB::table('hospitals')
                ->where('code', 'MAIN')
                ->whereNull('default_consultation_service_id')
                ->update(['default_consultation_service_id' => $consultGpId]);
        }
    }

    public function down(): void
    {
        Schema::table('hospitals', function (Blueprint $table) {
            $table->dropForeignIdFor(Service::class, 'default_consultation_service_id');
            $table->dropColumn('default_consultation_service_id');
        });
    }
};
