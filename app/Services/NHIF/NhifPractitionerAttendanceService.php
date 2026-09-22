<?php

namespace App\Services\NHIF;

use App\Models\Hospital;
use App\Models\NhifPractitionerAttendance;
use App\Models\Practitioner;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

/**
 * Registers practitioner login/logout attendance with NHIF (Phase 8, Step 67).
 *
 * Each operation is append-only; the most recent successful login record
 * determines whether the practitioner is currently "logged in" to NHIF.
 */
class NhifPractitionerAttendanceService
{
    public function __construct(
        protected NhifHttpClient $client,
        protected AuditLogService $auditLog,
    ) {}

    /**
     * Record a practitioner login with NHIF and persist the result.
     */
    public function login(Practitioner $practitioner, Hospital $hospital, Request $request): NhifPractitionerAttendance
    {
        return $this->record(
            $practitioner,
            $hospital,
            NhifPractitionerAttendance::OPERATION_LOGIN,
            $request,
        );
    }

    /**
     * Record a practitioner logout with NHIF and persist the result.
     */
    public function logout(Practitioner $practitioner, Hospital $hospital, Request $request): NhifPractitionerAttendance
    {
        return $this->record(
            $practitioner,
            $hospital,
            NhifPractitionerAttendance::OPERATION_LOGOUT,
            $request,
        );
    }

    /**
     * Whether this practitioner currently has a successful login recorded (no subsequent logout).
     */
    public function isLoggedIn(Practitioner $practitioner): bool
    {
        $latest = NhifPractitionerAttendance::where('practitioner_id', $practitioner->id)
            ->where('status', NhifPractitionerAttendance::STATUS_SUCCESS)
            ->latest('recorded_at')
            ->first();

        return $latest && $latest->operation === NhifPractitionerAttendance::OPERATION_LOGIN;
    }

    protected function record(
        Practitioner $practitioner,
        Hospital $hospital,
        string $operation,
        Request $request,
    ): NhifPractitionerAttendance {
        $practitionerNo = $practitioner->practitioner_number ?? '';
        $facilityCode = $hospital->nhif_facility_code ?? $hospital->code ?? '';

        $apiResult = $operation === NhifPractitionerAttendance::OPERATION_LOGIN
            ? $this->client->loginPractitioner($practitionerNo, $facilityCode)
            : $this->client->logoutPractitioner($practitionerNo, $facilityCode);

        $status = $apiResult['success']
            ? NhifPractitionerAttendance::STATUS_SUCCESS
            : NhifPractitionerAttendance::STATUS_FAILED;

        $record = NhifPractitionerAttendance::create([
            'practitioner_id' => $practitioner->id,
            'operation' => $operation,
            'status' => $status,
            'request_data' => ['practitioner_no' => $practitionerNo, 'facility_code' => $facilityCode],
            'response_data' => $apiResult['data'],
            'recorded_at' => now(),
            'recorded_by' => $request->user()?->id,
        ]);

        $this->auditLog->record(
            "nhif_practitioner_{$operation}",
            $practitioner,
            null,
            ['status' => $status, 'operation' => $operation],
            $request,
        );

        return $record;
    }
}
