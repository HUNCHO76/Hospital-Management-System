<?php

namespace App\Http\Controllers;

use App\Models\NhifPreauthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Provides authorization context (approved preauthorizations) for use by
 * other modules (lab, radiology, pharmacy) when determining whether a service
 * has active NHIF pre-approval.
 */
class NhifAuthorizationContextController extends Controller
{
    public function index(Request $request): Response
    {
        $contexts = NhifPreauthorization::with(['patientInsurance.patient', 'hospital'])
            ->where('status', NhifPreauthorization::STATUS_APPROVED)
            ->when($request->patient_id, fn ($q, $pid) => $q->whereHas('patientInsurance', fn ($q) => $q->where('patient_id', $pid)))
            ->orderByDesc('resolved_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (NhifPreauthorization $p) => [
                'id' => $p->id,
                'status' => $p->status,
                'source_type' => $p->source_type,
                'source_id' => $p->source_id,
                'approved_amount' => $p->approved_amount,
                'resolved_at' => $p->resolved_at?->toDateTimeString(),
                'patient' => $p->patientInsurance?->patient ? [
                    'id' => $p->patientInsurance->patient->id,
                    'name' => $p->patientInsurance->patient->full_name,
                ] : null,
                'hospital' => $p->hospital ? ['name' => $p->hospital->name] : null,
            ]);

        return Inertia::render('nhif/authorization-context/index', [
            'contexts' => $contexts,
            'filters' => $request->only('patient_id'),
        ]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_insurance_id' => ['nullable', 'integer', 'exists:patient_insurances,id'],
            'patient_id' => ['nullable', 'integer', 'exists:patients,id'],
            'source_type' => ['nullable', 'string'],
            'source_id' => ['nullable', 'integer'],
        ]);

        $query = NhifPreauthorization::where('status', NhifPreauthorization::STATUS_APPROVED);

        if (! empty($data['patient_insurance_id'])) {
            $query->where('patient_insurance_id', $data['patient_insurance_id']);
        } elseif (! empty($data['patient_id'])) {
            $query->whereHas('patientInsurance', fn ($q) => $q->where('patient_id', $data['patient_id']));
        } else {
            return response()->json(['error' => 'Must provide patient_insurance_id or patient_id.'], 400);
        }

        if (! empty($data['source_type']) && ! empty($data['source_id'])) {
            $query->where('source_type', $data['source_type'])
                ->where('source_id', $data['source_id']);
        }

        $preauth = $query->latest()->first();

        if (! $preauth) {
            $patientId = $data['patient_id'] ?? null;
            $insuranceId = $data['patient_insurance_id'] ?? null;

            $insurance = null;
            if ($insuranceId) {
                $insurance = \App\Models\PatientInsurance::find($insuranceId);
            } elseif ($patientId) {
                $patient = \App\Models\Patient::find($patientId);
                $insurance = $patient?->activeNhifInsurance()
                    ?? $patient?->insurances()->whereNotNull('nhif_authorization_no')->latest()->first();
            }

            if ($insurance && ! empty($insurance->nhif_authorization_no)) {
                return response()->json([
                    'found' => true,
                    'authorization_no' => $insurance->nhif_authorization_no,
                    'patient_insurance_id' => $insurance->id,
                    'preauthorization' => [
                        'id' => null,
                        'authorization_no' => $insurance->nhif_authorization_no,
                        'approved_amount' => null,
                        'source_type' => 'insurance',
                        'source_id' => $insurance->id,
                        'resolved_at' => optional($insurance->verified_at)->toDateTimeString(),
                    ],
                ]);
            }

            return response()->json(['found' => false, 'preauthorization' => null]);
        }

        return response()->json([
            'found' => true,
            'authorization_no' => $preauth->authorization_no,
            'patient_insurance_id' => $preauth->patient_insurance_id,
            'preauthorization' => [
                'id' => $preauth->id,
                'authorization_no' => $preauth->authorization_no,
                'approved_amount' => $preauth->approved_amount,
                'source_type' => $preauth->source_type,
                'source_id' => $preauth->source_id,
                'resolved_at' => $preauth->resolved_at?->toDateTimeString(),
            ],
        ]);
    }
}
