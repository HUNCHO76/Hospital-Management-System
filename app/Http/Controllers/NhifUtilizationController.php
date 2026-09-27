<?php

namespace App\Http\Controllers;

use App\Models\NhifUtilization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NhifUtilizationController extends Controller
{
    public function index(Request $request): Response
    {
        $utilizations = NhifUtilization::with(['hospital', 'patient', 'patientInsurance'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->module, fn ($q, $m) => $q->where('module', $m))
            ->when($request->search, fn ($q, $s) => $q->where('utilization_number', 'like', "%{$s}%"))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (NhifUtilization $u) => [
                'id' => $u->id,
                'utilization_number' => $u->utilization_number,
                'module' => $u->module,
                'status' => $u->status,
                'nhif_item_code' => $u->nhif_item_code,
                'nhif_item_name' => $u->nhif_item_name,
                'quantity' => $u->quantity,
                'hms_amount' => $u->hms_amount,
                'nhif_approved_amount' => $u->nhif_approved_amount,
                'attempt_count' => $u->attempt_count,
                'hospital' => $u->hospital ? ['name' => $u->hospital->name] : null,
                'patient' => $u->patient ? ['id' => $u->patient->id, 'name' => $u->patient->full_name] : null,
                'created_at' => $u->created_at->toDateTimeString(),
            ]);

        return Inertia::render('nhif/utilizations/index', [
            'utilizations' => $utilizations,
            'filters' => $request->only('status', 'module', 'search'),
            'statuses' => [
                NhifUtilization::STATUS_PENDING,
                NhifUtilization::STATUS_SUBMITTED,
                NhifUtilization::STATUS_ACCEPTED,
                NhifUtilization::STATUS_REJECTED,
                NhifUtilization::STATUS_RECONCILED,
                NhifUtilization::STATUS_FAILED,
                NhifUtilization::STATUS_EXCLUDED,
            ],
            'modules' => [
                NhifUtilization::MODULE_LAB,
                NhifUtilization::MODULE_RADIOLOGY,
                NhifUtilization::MODULE_PHARMACY,
                NhifUtilization::MODULE_CONSULTATION,
                NhifUtilization::MODULE_PROCEDURE,
            ],
        ]);
    }

    public function show(Request $request, NhifUtilization $nhifUtilization): Response
    {
        $nhifUtilization->load(['hospital', 'patient', 'patientInsurance', 'nhifServiceMapping', 'nhifPreauthorization', 'claimItem']);

        return Inertia::render('nhif/utilizations/show', [
            'utilization' => [
                'id' => $nhifUtilization->id,
                'utilization_number' => $nhifUtilization->utilization_number,
                'module' => $nhifUtilization->module,
                'status' => $nhifUtilization->status,
                'nhif_item_code' => $nhifUtilization->nhif_item_code,
                'nhif_item_name' => $nhifUtilization->nhif_item_name,
                'quantity' => $nhifUtilization->quantity,
                'hms_unit_price' => $nhifUtilization->hms_unit_price,
                'hms_amount' => $nhifUtilization->hms_amount,
                'nhif_allowable_unit_price' => $nhifUtilization->nhif_allowable_unit_price,
                'nhif_approved_amount' => $nhifUtilization->nhif_approved_amount,
                'service_date' => $nhifUtilization->service_date,
                'attempt_count' => $nhifUtilization->attempt_count,
                'error_message' => $nhifUtilization->error_message,
                'hospital' => $nhifUtilization->hospital ? ['name' => $nhifUtilization->hospital->name] : null,
                'patient' => $nhifUtilization->patient ? ['id' => $nhifUtilization->patient->id, 'name' => $nhifUtilization->patient->full_name] : null,
                'patient_insurance' => $nhifUtilization->patientInsurance ? [
                    'id' => $nhifUtilization->patientInsurance->id,
                    'membership_number' => $nhifUtilization->patientInsurance->membership_number,
                ] : null,
                'service_mapping' => $nhifUtilization->nhifServiceMapping ? [
                    'id' => $nhifUtilization->nhifServiceMapping->id,
                    'nhif_item_code' => $nhifUtilization->nhifServiceMapping->nhif_item_code,
                ] : null,
                'has_preauthorization' => $nhifUtilization->nhif_preauthorization_id !== null,
                'in_claim' => $nhifUtilization->claimItem !== null,
                'created_at' => $nhifUtilization->created_at->toDateTimeString(),
                'updated_at' => $nhifUtilization->updated_at->toDateTimeString(),
            ],
        ]);
    }

    public function submit(Request $request, NhifUtilization $nhifUtilization): RedirectResponse
    {
        if (! $nhifUtilization->isSubmittable()) {
            return back()->with('error', 'This utilization cannot be submitted (status: '.$nhifUtilization->status.').');
        }

        $nhifUtilization->update([
            'status' => NhifUtilization::STATUS_SUBMITTED,
            'attempt_count' => $nhifUtilization->attempt_count + 1,
            'last_attempt_at' => now(),
        ]);

        return back()->with('success', 'Utilization submitted.');
    }

    public function attachAuthorization(Request $request, NhifUtilization $nhifUtilization): RedirectResponse
    {
        $data = $request->validate([
            'nhif_preauthorization_id' => ['required', 'integer', 'exists:nhif_preauthorizations,id'],
        ]);

        $nhifUtilization->update($data);

        return back()->with('success', 'Pre-authorisation attached.');
    }

    public function reconcile(Request $request, NhifUtilization $nhifUtilization): RedirectResponse
    {
        $data = $request->validate([
            'nhif_approved_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $nhifUtilization->update([
            'nhif_approved_amount' => $data['nhif_approved_amount'],
            'status' => NhifUtilization::STATUS_RECONCILED,
        ]);

        return back()->with('success', 'Utilization reconciled.');
    }
}
