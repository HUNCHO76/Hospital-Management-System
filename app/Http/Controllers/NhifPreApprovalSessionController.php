<?php

namespace App\Http\Controllers;

use App\Models\NhifPreApprovalSession;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Services\NHIF\NhifPreApprovalSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NhifPreApprovalSessionController extends Controller
{
    public function index(Request $request, Patient $patient, PatientInsurance $patientInsurance): Response
    {
        $this->authorize('viewAny', NhifPreApprovalSession::class);

        $sessions = NhifPreApprovalSession::with('items')
            ->where('patient_insurance_id', $patientInsurance->id)
            ->latest()
            ->get();

        return Inertia::render('nhif/pre-approval-sessions/index', [
            'patient' => $patient,
            'insurance' => $patientInsurance,
            'sessions' => $sessions,
        ]);
    }

    public function create(Request $request, Patient $patient, PatientInsurance $patientInsurance): Response
    {
        $this->authorize('create', NhifPreApprovalSession::class);

        return Inertia::render('nhif/pre-approval-sessions/create', [
            'patient' => $patient,
            'insurance' => $patientInsurance->load('patient'),
            'has_authorization_no' => filled($patientInsurance->nhif_authorization_no),
            'authorization_no' => $patientInsurance->nhif_authorization_no,
        ]);
    }

    public function store(
        Request $request,
        Patient $patient,
        PatientInsurance $patientInsurance,
        NhifPreApprovalSessionService $service,
    ): RedirectResponse {
        $this->authorize('create', NhifPreApprovalSession::class);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.source_type' => ['nullable', 'string', 'in:consultation,lab,medication'],
            'items.*.source_id' => ['nullable', 'integer'],
            'items.*.nhif_item_code' => ['required', 'string', 'max:100'],
            'items.*.nhif_item_name' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        $session = $service->create($patientInsurance, null, $data['items'], $request);

        return redirect()
            ->route('patients.insurance.nhif.pre-approval-sessions.show', [$patient, $patientInsurance, $session])
            ->with('success', 'Pre-approval session created.');
    }

    public function show(
        Request $request,
        Patient $patient,
        PatientInsurance $patientInsurance,
        NhifPreApprovalSession $nhifPreApprovalSession,
    ): Response {
        $this->authorize('view', $nhifPreApprovalSession);

        $nhifPreApprovalSession->load('items');

        return Inertia::render('nhif/pre-approval-sessions/show', [
            'patient' => $patient,
            'insurance' => $patientInsurance,
            'session' => $nhifPreApprovalSession,
        ]);
    }

    public function submit(
        Request $request,
        Patient $patient,
        PatientInsurance $patientInsurance,
        NhifPreApprovalSession $nhifPreApprovalSession,
        NhifPreApprovalSessionService $service,
    ): RedirectResponse {
        $this->authorize('update', $nhifPreApprovalSession);

        $service->submit($nhifPreApprovalSession, $request);

        return redirect()
            ->route('patients.insurance.nhif.pre-approval-sessions.show', [$patient, $patientInsurance, $nhifPreApprovalSession])
            ->with('success', 'Services submitted to NHIF.');
    }

    public function sync(
        Request $request,
        Patient $patient,
        PatientInsurance $patientInsurance,
        NhifPreApprovalSession $nhifPreApprovalSession,
        NhifPreApprovalSessionService $service,
    ): RedirectResponse {
        $this->authorize('update', $nhifPreApprovalSession);

        $service->syncStatus($nhifPreApprovalSession, $request);

        return redirect()
            ->route('patients.insurance.nhif.pre-approval-sessions.show', [$patient, $patientInsurance, $nhifPreApprovalSession])
            ->with('success', 'Approval status synced.');
    }

    public function issue(
        Request $request,
        Patient $patient,
        PatientInsurance $patientInsurance,
        NhifPreApprovalSession $nhifPreApprovalSession,
        NhifPreApprovalSessionService $service,
    ): RedirectResponse {
        $this->authorize('update', $nhifPreApprovalSession);

        $service->issue($nhifPreApprovalSession, $request);

        return redirect()
            ->route('patients.insurance.nhif.pre-approval-sessions.show', [$patient, $patientInsurance, $nhifPreApprovalSession])
            ->with('success', 'Approved services issued.');
    }

    public function cancel(
        Request $request,
        Patient $patient,
        PatientInsurance $patientInsurance,
        NhifPreApprovalSession $nhifPreApprovalSession,
        NhifPreApprovalSessionService $service,
    ): RedirectResponse {
        $this->authorize('update', $nhifPreApprovalSession);

        $service->cancel($nhifPreApprovalSession, $request);

        return redirect()
            ->route('patients.insurance.nhif.pre-approval-sessions.show', [$patient, $patientInsurance, $nhifPreApprovalSession])
            ->with('success', 'Session cancelled.');
    }
}
