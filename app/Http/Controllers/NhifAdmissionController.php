<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Services\NHIF\NhifHttpClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class NhifAdmissionController extends Controller
{
    public function index(NhifHttpClient $client): Response
    {
        $this->authorize('viewAny', Admission::class);

        $result = $client->getAdmittedPatients();
        $admittedPatients = $result['success'] ? ($result['data'] ?? []) : [];

        $wardNames = [
            21 => 'Normal Ward',
            22 => 'ICU',
        ];

        $roomNames = [
            1 => 'General',
            2 => 'Private',
            3 => 'Semi-Private',
        ];

        foreach ($admittedPatients as &$p) {
            $authNo = $p['AuthorizationNo'] ?? null;
            $cardNo = $p['CardNo'] ?? null;

            // Check local nhif_admissions
            $nhifAdm = null;
            if ($authNo) {
                $nhifAdm = DB::table('nhif_admissions')->where('nhif_authorization_no', $authNo)->first();
            }

            // Check local admission
            $localAdm = null;
            if ($nhifAdm && $nhifAdm->admission_id) {
                $localAdm = Admission::with(['patient', 'patientInsurance'])->find($nhifAdm->admission_id);
            }
            if (! $localAdm && $cardNo) {
                $localAdm = Admission::whereHas('patient.insurances', function ($q) use ($cardNo) {
                    $q->where('card_number', $cardNo);
                })->latest()->first();
            }

            $admissionNo = $p['AdmissionNo'] ?? ($nhifAdm?->nhif_admission_no ?? null);
            $schemeId = $p['SchemeID'] ?? null;
            $wardTypeId = $p['WardTypeID'] ?? ($nhifAdm?->nhif_ward_type_id ?? null);
            $roomTypeId = $p['RoomTypeID'] ?? ($nhifAdm?->nhif_room_type_id ?? null);

            // If AdmissionNo is not yet present, look up live details via authorization number
            if (empty($admissionNo) && $authNo) {
                $detailsRes = $client->getAdmissionDetailsByAuthorizationNo($authNo);
                if ($detailsRes['success'] && ! empty($detailsRes['data'])) {
                    $first = is_array($detailsRes['data']) && isset($detailsRes['data'][0]) ? $detailsRes['data'][0] : $detailsRes['data'];
                    $admissionNo = $first['AdmissionNo'] ?? null;
                    $schemeId = $first['SchemeID'] ?? $schemeId;
                    if ($admissionNo && $nhifAdm) {
                        DB::table('nhif_admissions')->where('id', $nhifAdm->id)->update([
                            'nhif_admission_no' => $admissionNo,
                            'nhif_status' => 'approved',
                        ]);
                    }
                }
            }

            $wardDisplay = $localAdm?->ward_type 
                ?? ($wardTypeId ? ($wardNames[$wardTypeId] ?? "Ward #{$wardTypeId}") : null);
            $roomDisplay = $localAdm?->room_type 
                ?? ($roomTypeId ? ($roomNames[$roomTypeId] ?? "Room #{$roomTypeId}") : null);

            $schemeDisplay = $p['ProductName'] 
                ?? ($schemeId ? "Scheme {$schemeId}" : ($p['ProductCode'] ?? $localAdm?->patientInsurance?->insurance_name ?? null));

            // Set normalized fields for frontend
            $p['AdmissionNo'] = $admissionNo ?? ($localAdm?->admission_number ?? null);
            $p['Scheme'] = $schemeDisplay . ($schemeId && $schemeDisplay !== "Scheme {$schemeId}" ? " (ID: {$schemeId})" : "");
            $p['SchemeID'] = $schemeId;
            $p['WardDisplay'] = $wardDisplay;
            $p['RoomDisplay'] = $roomDisplay;
            $p['LocalAdmissionID'] = $localAdm?->id;
            $p['LocalAdmissionNumber'] = $localAdm?->admission_number;
        }
        unset($p);

        return Inertia::render('nhif/admissions/index', [
            'admittedPatients' => $admittedPatients,
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
