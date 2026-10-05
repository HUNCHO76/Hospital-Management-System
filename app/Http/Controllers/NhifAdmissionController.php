<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Services\NHIF\NhifHttpClient;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NhifAdmissionController extends Controller
{
    public function index(NhifHttpClient $client): Response
    {
        $this->authorize('viewAny', Admission::class);

        $result = $client->getAdmittedPatients();

        return Inertia::render('nhif/admissions/index', [
            'admittedPatients' => $result['success'] ? ($result['data'] ?? []) : [],
            'error' => $result['success'] ? null : ($result['error'] ?? 'Failed to fetch admitted patients.'),
        ]);
    }

    public function lookup(Request $request, NhifHttpClient $client)
    {
        $this->authorize('viewAny', Admission::class);

        $authNo = $request->input('authorization_no') ?? $request->input('authorizationNo');

        if (empty($authNo)) {
            return response()->json(['error' => 'The authorization number is required.'], 422);
        }

        $result = $client->getAdmissionDetailsByAuthorizationNo((string) $authNo);

        if (! $result['success']) {
            return response()->json([
                'error' => $result['error'] ?? 'Failed to lookup admission.',
            ], 400);
        }

        return response()->json($result['data']);
    }

    public function sync(Request $request, NhifHttpClient $client)
    {
        $this->authorize('viewAny', Admission::class);

        $validated = $request->validate([
            'admission_id' => 'required|integer|exists:admissions,id',
            'authorization_no' => 'required|string',
        ]);

        $result = $client->getAdmissionDetailsByAuthorizationNo($validated['authorization_no']);

        if (! $result['success'] || empty($result['data'])) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to retrieve live admission from NHIF.',
            ], 400);
        }

        $data = $result['data'];
        $admissionNo = $data['AdmissionNo'] ?? $data['admissionNo'] ?? null;

        $admission = Admission::with(['hospital', 'practitioner', 'nhifAdmission'])->findOrFail($validated['admission_id']);
        
        $nhifAdmission = \App\Models\NhifAdmission::updateOrCreate(
            ['admission_id' => $admission->id],
            [
                'nhif_admission_no' => $admissionNo ?: $admission->nhifAdmission?->nhif_admission_no,
                'nhif_authorization_no' => $validated['authorization_no'],
                'nhif_facility_code' => $data['FacilityCode'] ?? $admission->hospital?->code,
                'nhif_practitioner_no' => $data['AdmittingPractitionerNo'] ?? $admission->practitioner?->nhif_practitioner_no,
                'nhif_status' => \App\Models\NhifAdmission::STATUS_SUBMITTED,
                'raw_response' => json_encode($data),
                'resolved_at' => now(),
            ]
        );

        if (! empty($admissionNo) || ! empty($nhifAdmission->nhif_admission_no)) {
            app(\App\Services\AdmissionService::class)->settleAdmissionChargeForNhif($admission, $request);
        }

        return response()->json([
            'success' => true,
            'message' => 'Admission details successfully synchronized with NHIF.',
            'admission' => $admission->fresh(['nhifAdmission']),
            'nhif_details' => $data,
        ]);
    }
}
