<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\ClinicalEncounter;
use App\Models\Hospital;
use App\Models\NhifBillConfirmation;
use App\Models\NhifClaim;
use App\Models\NhifClaimDocument;
use App\Models\NhifClaimItem;
use App\Models\NhifMonthlySubmission;
use App\Models\NhifUtilization;
use App\Models\PatientInsurance;
use App\Services\NHIF\ClaimCalculationService;
use App\Services\NHIF\NhifFolioService;
use App\Services\NHIF\NhifMonthlyClaimService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class NhifClaimController extends Controller
{
    public function index(Request $request): Response
    {
        $claims = NhifClaim::with(['hospital', 'patient'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->claim_type, fn ($q, $t) => $q->where('claim_type', $t))
            ->when($request->search, fn ($q, $s) => $q->where('claim_number', 'like', "%{$s}%"))
            ->orderByDesc('updated_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (NhifClaim $c) => [
                'id' => $c->id,
                'claim_number' => $c->claim_number,
                'status' => $c->status,
                'claim_type' => $c->claim_type,
                'folio_no' => $c->folio_no,
                'hms_total' => $c->hms_total,
                'nhif_allowable_total' => $c->nhif_allowable_total,
                'nhif_approved_total' => $c->nhif_approved_total,
                'hospital' => $c->hospital ? ['name' => $c->hospital->name] : null,
                'patient' => $c->patient ? ['id' => $c->patient->id, 'name' => $c->patient->full_name] : null,
                'updated_at' => $c->updated_at->toDateTimeString(),
            ]);

        return Inertia::render('nhif/claims/index', [
            'claims' => $claims,
            'filters' => $request->only('status', 'claim_type', 'search'),
            'statuses' => [
                NhifClaim::STATUS_DRAFT,
                NhifClaim::STATUS_VALIDATED,
                NhifClaim::STATUS_READY,
                NhifClaim::STATUS_SUBMITTED,
                NhifClaim::STATUS_ACCEPTED,
                NhifClaim::STATUS_REJECTED,
                NhifClaim::STATUS_RECONCILED,
                NhifClaim::STATUS_CANCELLED,
            ],
            'claimTypes' => [NhifClaim::TYPE_OUTPATIENT, NhifClaim::TYPE_INPATIENT],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'hospital_id' => ['required', 'integer', 'exists:hospitals,id'],
            'patient_id' => ['required', 'integer', 'exists:patients,id'],
            'patient_insurance_id' => ['required', 'integer', 'exists:patient_insurances,id'],
            'claim_type' => ['required', 'in:outpatient,inpatient'],
            'attendance_date' => ['required', 'date'],
        ]);

        $claim = NhifClaim::create(array_merge($data, [
            'status' => NhifClaim::STATUS_DRAFT,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]));

        return redirect()->route('nhif.claims.show', $claim)
            ->with('success', 'Claim draft created.');
    }

    public function show(Request $request, NhifClaim $nhifClaim): Response
    {
        $nhifClaim->load(['hospital', 'patient', 'patientInsurance', 'activeItems.utilization', 'documents', 'submissionAttempts', 'reconciliations']);

        return Inertia::render('nhif/claims/show', [
            'claim' => [
                'id' => $nhifClaim->id,
                'claim_number' => $nhifClaim->claim_number,
                'status' => $nhifClaim->status,
                'claim_type' => $nhifClaim->claim_type,
                'folio_no' => $nhifClaim->folio_no,
                'attendance_date' => $nhifClaim->attendance_date?->toDateString(),
                'primary_diagnosis_code' => $nhifClaim->primary_diagnosis_code,
                'primary_diagnosis_description' => $nhifClaim->primary_diagnosis_description,
                'hms_total' => $nhifClaim->hms_total,
                'nhif_allowable_total' => $nhifClaim->nhif_allowable_total,
                'nhif_approved_total' => $nhifClaim->nhif_approved_total,
                'nhif_rejected_total' => $nhifClaim->nhif_rejected_total,
                'validation_summary' => $nhifClaim->validation_summary,
                'validated_at' => $nhifClaim->validated_at?->toDateTimeString(),
                'cancellation_reason' => $nhifClaim->cancellation_reason,
                'cancelled_at' => $nhifClaim->cancelled_at?->toDateTimeString(),
                'is_submittable' => $nhifClaim->isSubmittable(),
                'is_cancelled' => $nhifClaim->isCancelled(),
                'hospital' => $nhifClaim->hospital ? ['id' => $nhifClaim->hospital->id, 'name' => $nhifClaim->hospital->name] : null,
                'patient' => $nhifClaim->patient ? ['id' => $nhifClaim->patient->id, 'name' => $nhifClaim->patient->full_name] : null,
                'patient_insurance' => $nhifClaim->patientInsurance ? [
                    'id' => $nhifClaim->patientInsurance->id,
                    'membership_number' => $nhifClaim->patientInsurance->membership_number,
                ] : null,
                'items' => $nhifClaim->activeItems->map(fn ($item) => [
                    'id' => $item->id,
                    'line_no' => $item->line_no,
                    'module' => $item->module,
                    'nhif_item_code' => $item->nhif_item_code,
                    'service_date' => $item->service_date,
                    'quantity' => $item->quantity,
                    'hms_amount' => $item->hms_amount,
                    'nhif_allowable_amount' => $item->nhif_allowable_amount,
                    'utilization_number' => $item->utilization?->utilization_number,
                ]),
                'documents' => $nhifClaim->documents->map(fn ($d) => [
                    'id' => $d->id,
                    'original_name' => $d->original_name,
                    'size_bytes' => $d->size_bytes,
                    'created_at' => $d->created_at->toDateTimeString(),
                ]),
                'submission_attempts' => $nhifClaim->submissionAttempts->map(fn ($a) => [
                    'id' => $a->id,
                    'attempt_number' => $a->attempt_number,
                    'submission_status' => $a->submission_status,
                    'submitted_at' => $a->submitted_at?->toDateTimeString(),
                ]),
                'updated_at' => $nhifClaim->updated_at->toDateTimeString(),
            ],
        ]);
    }

    public function regenerate(Request $request, NhifClaim $nhifClaim): RedirectResponse
    {
        if ($nhifClaim->isCancelled()) {
            return back()->with('error', 'Cannot regenerate a cancelled claim.');
        }

        $nhifClaim->update([
            'generation_count' => $nhifClaim->generation_count + 1,
            'generated_at' => now(),
            'status' => NhifClaim::STATUS_DRAFT,
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Claim regenerated.');
    }

    public function validateClaim(Request $request, NhifClaim $nhifClaim): RedirectResponse
    {
        if (! in_array($nhifClaim->status, [NhifClaim::STATUS_DRAFT, NhifClaim::STATUS_REJECTED], true)) {
            return back()->with('error', 'Only draft or rejected claims can be validated.');
        }

        $nhifClaim->update([
            'status' => NhifClaim::STATUS_VALIDATED,
            'validated_at' => now(),
            'validated_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Claim validated.');
    }

    public function finalize(Request $request, NhifClaim $nhifClaim): RedirectResponse
    {
        if ($nhifClaim->status !== NhifClaim::STATUS_VALIDATED) {
            return back()->with('error', 'Only validated claims can be finalized.');
        }

        $nhifClaim->update([
            'status' => NhifClaim::STATUS_READY,
            'ready_at' => now(),
            'ready_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Claim finalized and ready for submission.');
    }

    public function submit(Request $request, NhifClaim $nhifClaim, NhifFolioService $folioService): RedirectResponse
    {
        if (! $nhifClaim->isSubmittable()) {
            return back()->with('error', 'Claim cannot be submitted in its current state ('.$nhifClaim->status.').');
        }

        // Prevent duplicate submission
        if (filled($nhifClaim->nhif_claim_reference)) {
            return back()->with('error', 'This folio has already been submitted to NHIF (ref: '.$nhifClaim->nhif_claim_reference.').');
        }

        $updated = $folioService->submit($nhifClaim, $request);

        if ($updated->status === NhifClaim::STATUS_SUBMITTED) {
            return back()->with('success', 'Folio submitted to NHIF OCS successfully. Submission No: '.$updated->nhif_submission_no);
        }

        return back()->with('error', 'NHIF submission failed. The claim was not marked as submitted. Please check the submission log.');
    }

    public function preview(Request $request, ClaimCalculationService $calculator): JsonResponse
    {
        $this->authorize('viewAny', NhifClaim::class);

        $context = $this->resolveContextFromRequest($request);
        $readiness = $calculator->validateClaimReadiness($context);

        return response()->json($readiness);
    }

    public function sign(Request $request, NhifClaim $nhifClaim, NhifFolioService $folioService): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $nhifClaim);

        $data = $request->validate([
            'signature_method' => ['nullable', 'string', 'max:50'],
            'signature_data' => ['nullable', 'string'],
            'imageData' => ['nullable', 'string'],
            'fp_code' => ['nullable', 'string', 'max:10'],
            'fpCode' => ['nullable', 'string', 'max:10'],
            'signed_by' => ['nullable', 'string', 'max:100'],
            'signed_by_name' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        if (isset($data['imageData']) && ! isset($data['signature_data'])) {
            $data['signature_data'] = $data['imageData'];
        }
        if (isset($data['fpCode']) && ! isset($data['fp_code'])) {
            $data['fp_code'] = $data['fpCode'];
        }

        $updated = $folioService->sign($nhifClaim, $data, $request);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => $updated->status === NhifClaim::STATUS_SIGNED,
                'claim' => $updated,
            ]);
        }

        if ($updated->status === NhifClaim::STATUS_SIGNED) {
            return back()->with('success', 'Folio signed successfully.');
        }

        return back()->with('error', 'Signing failed: '.($updated->signature_response['error'] ?? 'Unknown error'));
    }

    public function finalizeFromContext(Request $request, NhifFolioService $folioService, ClaimCalculationService $calculator): RedirectResponse|JsonResponse
    {
        $this->authorize('create', NhifClaim::class);

        $context = $this->resolveContextFromRequest($request);
        $data = $request->validate([
            'signature_method' => ['nullable', 'string', 'max:50'],
            'signature_data' => ['nullable', 'string'],
            'imageData' => ['nullable', 'string'],
            'fp_code' => ['nullable', 'string', 'max:10'],
            'fpCode' => ['nullable', 'string', 'max:10'],
            'signed_by' => ['nullable', 'string', 'max:100'],
            'signed_by_name' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        if (isset($data['imageData']) && ! isset($data['signature_data'])) {
            $data['signature_data'] = $data['imageData'];
        }
        if (isset($data['fpCode']) && ! isset($data['fp_code'])) {
            $data['fp_code'] = $data['fpCode'];
        }

        $result = $folioService->finalizeClaim($context, $data, $request);

        if ($request->wantsJson()) {
            return response()->json($result, $result['success'] ? 200 : 422);
        }

        if ($result['success']) {
            return back()->with('success', 'NHIF Claim finalized and submitted successfully. Submission No: '.($result['claim']->nhif_submission_no ?? 'Submitted'));
        }

        return back()->with('error', 'NHIF Claim Finalization failed at step "'.$result['step'].'": '.($result['error'] ?? 'Unknown error'));
    }

    public function retryBillConfirmation(Request $request, NhifClaim $nhifClaim, NhifFolioService $folioService): RedirectResponse
    {
        $this->authorize('submit', $nhifClaim);

        $result = $folioService->retryBillConfirmation($nhifClaim, $request);

        if ($result['success']) {
            return back()->with('success', 'Bill confirmation succeeded. Claim is finalized.');
        }

        return back()->with('error', 'NHIF bill confirmation failed: '.($result['error'] ?? 'Please check NHIF logs.'));
    }

    protected function resolveContextFromRequest(Request $request): ClinicalEncounter|Admission
    {
        if ($request->filled('clinical_encounter_id')) {
            return ClinicalEncounter::findOrFail($request->integer('clinical_encounter_id'));
        }

        if ($request->filled('admission_id')) {
            return Admission::findOrFail($request->integer('admission_id'));
        }

        if ($request->filled('encounter_id')) {
            return ClinicalEncounter::findOrFail($request->integer('encounter_id'));
        }

        throw ValidationException::withMessages([
            'context' => 'A valid clinical_encounter_id or admission_id is required.',
        ]);
    }


    public function receipt(Request $request, NhifClaim $nhifClaim, NhifFolioService $folioService): RedirectResponse
    {
        if ($nhifClaim->status !== NhifClaim::STATUS_SUBMITTED) {
            return back()->with('error', 'Receipt can only be retrieved for submitted folios.');
        }

        $result = $folioService->getReceipt($nhifClaim, $request);

        if ($result['success']) {
            return back()->with('success', 'Receipt retrieved from NHIF.')->with('receipt_data', $result['data']);
        }

        return back()->with('error', 'NHIF receipt request failed. '.$result['error']);
    }

    public function monthly(Request $request, NhifMonthlyClaimService $monthlyService): Response
    {
        $this->authorize('viewAny', NhifClaim::class);

        $user = $request->user();
        $staffHospitalId = $user->staff?->hospital_id;
        $isFacilityRestricted = $user->hasAnyRole(['hospital-admin', 'Manager', 'manager']) && $staffHospitalId;

        if ($isFacilityRestricted) {
            $hospitals = Hospital::query()->where('id', $staffHospitalId)->get(['id', 'name', 'code']);
            $selectedHospitalId = $staffHospitalId;
        } else {
            $hospitals = Hospital::query()->get(['id', 'name', 'code']);
            $selectedHospitalId = $request->input('hospital_id') ? (int) $request->input('hospital_id') : ($hospitals->first()?->id ?? 1);
        }

        $selectedYear = (int) ($request->input('claim_year') ?? now()->format('Y'));
        $selectedMonth = (int) ($request->input('claim_month') ?? now()->format('n'));

        $selectedHospital = $hospitals->firstWhere('id', $selectedHospitalId) ?? $hospitals->first();
        $preview = null;
        if ($selectedHospital) {
            $preview = $monthlyService->calculateMonthlyClaim($selectedHospital, $selectedYear, $selectedMonth);
        }

        $submissions = NhifMonthlySubmission::with(['hospital', 'submittedBy'])
            ->when($isFacilityRestricted ? $staffHospitalId : $request->input('hospital_id'), fn ($q, $hId) => $q->where('hospital_id', $hId))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (NhifMonthlySubmission $s) => [
                'id' => $s->id,
                'claim_year' => $s->claim_year,
                'claim_month' => $s->claim_month,
                'facility_code' => $s->facility_code,
                'folios_submitted' => $s->folios_submitted,
                'total_amount_claimed' => (float) $s->total_amount_claimed,
                'status' => $s->status,
                'submission_remarks' => $s->submission_remarks,
                'error_message' => $s->error_message,
                'retry_count' => $s->retry_count,
                'nhif_response' => $s->nhif_response,
                'nhif_request_payload' => $s->nhif_request_payload,
                'submitted_at' => $s->submitted_at?->toDateTimeString(),
                'submitted_by' => $s->submittedBy ? trim(($s->submittedBy->first_name ?? '').' '.($s->submittedBy->last_name ?? $s->submittedBy->name ?? '')) : null,
                'hospital' => $s->hospital ? ['id' => $s->hospital->id, 'name' => $s->hospital->name, 'facility_code' => $s->hospital->code] : null,
            ]);

        return Inertia::render('nhif/claims/monthly', [
            'submissions' => $submissions,
            'hospitals' => $hospitals,
            'selectedHospitalId' => $selectedHospitalId,
            'selectedYear' => $selectedYear,
            'selectedMonth' => $selectedMonth,
            'preview' => $preview,
        ]);
    }

    public function calculateMonthly(Request $request, NhifMonthlyClaimService $monthlyService): JsonResponse
    {
        $this->authorize('viewAny', NhifClaim::class);

        $user = $request->user();
        $staffHospitalId = $user->staff?->hospital_id;
        $isFacilityRestricted = $user->hasAnyRole(['hospital-admin', 'Manager', 'manager']) && $staffHospitalId;

        $requestedHospitalId = $request->input('hospital_id');
        if ($isFacilityRestricted && ! empty($requestedHospitalId) && (int) $requestedHospitalId !== $staffHospitalId) {
            abort(403, 'You are only authorized to calculate monthly claims for your assigned facility.');
        }

        $hospitalId = $isFacilityRestricted ? $staffHospitalId : ($requestedHospitalId ?? Hospital::first()?->id);
        $year = (int) ($request->input('claim_year') ?? now()->format('Y'));
        $month = (int) ($request->input('claim_month') ?? now()->format('n'));

        $hospital = Hospital::findOrFail($hospitalId);
        $data = $monthlyService->calculateMonthlyClaim($hospital, $year, $month);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function submitMonthly(Request $request, NhifMonthlyClaimService $monthlyService): RedirectResponse|JsonResponse
    {
        $this->authorize('monthlySubmit', NhifClaim::class);

        $data = $request->validate([
            'hospital_id' => ['nullable', 'integer', 'exists:hospitals,id'],
            'claim_year' => ['required', 'integer', 'min:2000', 'max:2099'],
            'claim_month' => ['required', 'integer', 'min:1', 'max:12'],
            'submission_remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();
        $staffHospitalId = $user->staff?->hospital_id;
        $isFacilityRestricted = $user->hasAnyRole(['hospital-admin', 'Manager', 'manager']) && $staffHospitalId;

        if ($isFacilityRestricted && ! empty($data['hospital_id']) && (int) $data['hospital_id'] !== $staffHospitalId) {
            abort(403, 'You are only authorized to submit monthly claims for your assigned facility.');
        }

        $hospitalId = $isFacilityRestricted ? $staffHospitalId : ($data['hospital_id'] ?? Hospital::first()?->id);
        $hospital = Hospital::findOrFail($hospitalId);

        try {
            $submission = $monthlyService->submitMonthlyClaim(
                $hospital,
                (int) $data['claim_year'],
                (int) $data['claim_month'],
                $data['submission_remarks'] ?? null,
                $request,
            );

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => $submission->status === NhifMonthlySubmission::STATUS_SUBMITTED,
                    'submission' => $submission,
                    'message' => $submission->status === NhifMonthlySubmission::STATUS_SUBMITTED
                        ? "Monthly claim for {$data['claim_year']}-{$data['claim_month']} successfully submitted to NHIF."
                        : ($submission->error_message ?? 'Monthly claim submission failed at NHIF.'),
                ], $submission->status === NhifMonthlySubmission::STATUS_SUBMITTED ? 200 : 422);
            }

            if ($submission->status === NhifMonthlySubmission::STATUS_SUBMITTED) {
                return redirect()->route('nhif.claims.monthly', [
                    'hospital_id' => $hospital->id,
                    'claim_year' => $data['claim_year'],
                    'claim_month' => $data['claim_month'],
                ])->with('success', "Monthly claim for {$data['claim_year']}-{$data['claim_month']} successfully submitted to NHIF.");
            }

            return back()->with('error', 'Monthly claim submission to NHIF failed: '.($submission->error_message ?? 'Check response details'));
        } catch (ValidationException $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => collect($e->errors())->flatten()->first(),
                    'errors' => $e->errors(),
                ], 422);
            }
            throw $e;
        }
    }

    public function retryMonthly(Request $request, NhifMonthlySubmission $nhifMonthlySubmission, NhifMonthlyClaimService $monthlyService): RedirectResponse|JsonResponse
    {
        $this->authorize('monthlySubmit', NhifClaim::class);

        $user = $request->user();
        $staffHospitalId = $user->staff?->hospital_id;
        if ($user->hasAnyRole(['hospital-admin', 'Manager', 'manager']) && $staffHospitalId && $nhifMonthlySubmission->hospital_id !== $staffHospitalId) {
            abort(403, 'You are not authorized to retry monthly submissions for another facility.');
        }

        $hospital = $nhifMonthlySubmission->hospital ?? Hospital::findOrFail($nhifMonthlySubmission->hospital_id);

        try {
            $submission = $monthlyService->submitMonthlyClaim(
                $hospital,
                $nhifMonthlySubmission->claim_year,
                $nhifMonthlySubmission->claim_month,
                $nhifMonthlySubmission->submission_remarks,
                $request,
            );

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => $submission->status === NhifMonthlySubmission::STATUS_SUBMITTED,
                    'submission' => $submission,
                    'message' => $submission->status === NhifMonthlySubmission::STATUS_SUBMITTED
                        ? "Monthly claim for {$submission->claim_year}-{$submission->claim_month} successfully submitted to NHIF."
                        : ($submission->error_message ?? 'Retry failed at NHIF.'),
                ], $submission->status === NhifMonthlySubmission::STATUS_SUBMITTED ? 200 : 422);
            }

            if ($submission->status === NhifMonthlySubmission::STATUS_SUBMITTED) {
                return back()->with('success', "Monthly claim for {$submission->claim_year}-{$submission->claim_month} successfully submitted to NHIF.");
            }

            return back()->with('error', 'Monthly claim retry failed: '.($submission->error_message ?? 'Check response details'));
        } catch (ValidationException $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => collect($e->errors())->flatten()->first(),
                    'errors' => $e->errors(),
                ], 422);
            }
            throw $e;
        }
    }

    public function billConfirmation(Request $request): Response
    {
        $confirmations = NhifBillConfirmation::with(['hospital', 'patientInsurance.patient'])
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (NhifBillConfirmation $c) => [
                'id' => $c->id,
                'nhif_authorization_no' => $c->nhif_authorization_no,
                'facility_code' => $c->facility_code,
                'attendance_date' => $c->attendance_date?->toDateString(),
                'total_amount' => $c->total_amount,
                'confirmation_code' => $c->confirmation_code,
                'confirmation_status' => $c->confirmation_status,
                'confirmed_at' => $c->confirmed_at?->toDateTimeString(),
                'hospital' => $c->hospital ? ['name' => $c->hospital->name] : null,
            ]);

        return Inertia::render('nhif/claims/bill-confirmation', [
            'confirmations' => $confirmations,
        ]);
    }

    public function requestBillConfirmation(Request $request, NhifFolioService $folioService): RedirectResponse
    {
        $data = $request->validate([
            'patient_insurance_id' => ['required', 'integer', 'exists:patient_insurances,id'],
            'hospital_id' => ['required', 'integer', 'exists:hospitals,id'],
            'attendance_date' => ['required', 'date'],
            'total_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $insurance = PatientInsurance::findOrFail($data['patient_insurance_id']);
        $hospital = Hospital::findOrFail($data['hospital_id']);

        $confirmation = $folioService->requestBillConfirmation(
            $insurance,
            $hospital,
            Carbon::parse($data['attendance_date']),
            (float) $data['total_amount'],
            $request,
        );

        if ($confirmation->confirmation_status !== NhifBillConfirmation::STATUS_FAILED) {
            return back()->with('success', 'Bill confirmation request sent to NHIF.');
        }

        return back()->with('error', 'NHIF bill confirmation request failed.');
    }

    public function cancel(Request $request, NhifClaim $nhifClaim): RedirectResponse
    {
        $data = $request->validate([
            'cancellation_reason' => ['required', 'string', 'max:500'],
        ]);

        if ($nhifClaim->isCancelled()) {
            return back()->with('error', 'Claim is already cancelled.');
        }

        $nhifClaim->update([
            'status' => NhifClaim::STATUS_CANCELLED,
            'cancellation_reason' => $data['cancellation_reason'],
            'cancelled_at' => now(),
            'cancelled_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Claim cancelled.');
    }

    public function reopen(Request $request, NhifClaim $nhifClaim): RedirectResponse
    {
        $this->authorize('update', $nhifClaim);

        if (! $nhifClaim->isCancelled()) {
            return back()->with('error', 'Only cancelled claims can be reopened.');
        }

        $newStatus = filled($nhifClaim->nhif_submission_no)
            ? (filled($nhifClaim->confirmation_code) ? NhifClaim::STATUS_FINALIZED : NhifClaim::STATUS_SUBMITTED)
            : NhifClaim::STATUS_DRAFT;

        $nhifClaim->update([
            'status' => $newStatus,
            'cancellation_reason' => null,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', "Claim {$nhifClaim->claim_number} reopened successfully (Status: {$newStatus}).");
    }

    public function addItem(Request $request, NhifClaim $nhifClaim): RedirectResponse
    {
        if (! in_array($nhifClaim->status, [NhifClaim::STATUS_DRAFT, NhifClaim::STATUS_VALIDATED], true)) {
            return back()->with('error', 'Items can only be added to draft or validated claims.');
        }

        $data = $request->validate([
            'nhif_utilization_id' => ['required', 'integer', 'exists:nhif_utilizations,id'],
        ]);

        $utilization = NhifUtilization::findOrFail($data['nhif_utilization_id']);
        $lineNo = $nhifClaim->items()->count() + 1;

        NhifClaimItem::create([
            'nhif_claim_id' => $nhifClaim->id,
            'nhif_utilization_id' => $utilization->id,
            'line_no' => $lineNo,
            'module' => $utilization->module,
            'nhif_item_code' => $utilization->nhif_item_code,
            'service_date' => $utilization->service_date,
            'quantity' => $utilization->quantity,
            'hms_amount' => $utilization->hms_amount,
            'nhif_allowable_amount' => $utilization->nhif_allowable_unit_price
                ? $utilization->nhif_allowable_unit_price * $utilization->quantity
                : $utilization->hms_amount,
        ]);

        return back()->with('success', 'Item added to claim.');
    }

    public function removeItem(Request $request, NhifClaim $nhifClaim, NhifClaimItem $nhifClaimItem): RedirectResponse
    {
        if (! in_array($nhifClaim->status, [NhifClaim::STATUS_DRAFT, NhifClaim::STATUS_VALIDATED], true)) {
            return back()->with('error', 'Items can only be removed from draft or validated claims.');
        }

        $nhifClaimItem->update(['removed_at' => now()]);

        return back()->with('success', 'Item removed from claim.');
    }

    public function uploadDocument(Request $request, NhifClaim $nhifClaim): RedirectResponse
    {
        $request->validate([
            'document' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $file = $request->file('document');
        $path = $file->store('nhif/claims/'.$nhifClaim->id, 'local');

        NhifClaimDocument::create([
            'nhif_claim_id' => $nhifClaim->id,
            'hospital_id' => $nhifClaim->hospital_id,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Document uploaded.');
    }

    public function downloadDocument(Request $request, NhifClaim $nhifClaim, NhifClaimDocument $nhifClaimDocument): BinaryFileResponse
    {
        abort_unless(Storage::disk('local')->exists($nhifClaimDocument->path), 404);

        return response()->download(
            Storage::disk('local')->path($nhifClaimDocument->path),
            $nhifClaimDocument->original_name
        );
    }

    public function destroyDocument(Request $request, NhifClaim $nhifClaim, NhifClaimDocument $nhifClaimDocument): RedirectResponse
    {
        Storage::disk('local')->delete($nhifClaimDocument->path);
        $nhifClaimDocument->delete();

        return back()->with('success', 'Document deleted.');
    }

    /**
     * Search and list completed/eligible NHIF visits (encounters and admissions) for claim generation.
     */
    public function eligibleVisits(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search', ''));
        $user = $request->user();
        $hospitalId = $user->staff?->hospital_id ?? Hospital::first()?->id;

        // 1. Clinical Encounters
        $encounters = ClinicalEncounter::query()
            ->with(['patient.insurances', 'department', 'practitioner'])
            ->whereHas('patient.insurances', function ($q) {
                $q->whereNotNull('nhif_authorization_no')
                    ->where('nhif_authorization_no', '!=', '');
            })
            ->when($hospitalId, function ($q) use ($hospitalId) {
                $q->whereHas('department', fn ($d) => $d->where('hospital_id', $hospitalId));
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('encounter_number', 'like', "%{$search}%")
                        ->orWhereHas('patient', function ($p) use ($search) {
                            $p->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('patient_number', 'like', "%{$search}%")
                                ->orWhereHas('insurances', function ($ins) use ($search) {
                                    $ins->where('card_number', 'like', "%{$search}%")
                                        ->orWhere('nhif_authorization_no', 'like', "%{$search}%");
                                });
                        });
                });
            })
            ->orderByDesc('started_at')
            ->limit(20)
            ->get()
            ->map(function (ClinicalEncounter $enc) {
                $insurance = $enc->patient?->activeNhifInsurance()
                    ?? $enc->patient?->insurances()->whereNotNull('nhif_authorization_no')->latest()->first();

                $existingClaim = NhifClaim::where('clinical_encounter_id', $enc->id)->where('status', '!=', NhifClaim::STATUS_CANCELLED)->latest()->first();

                return [
                    'id' => $enc->id,
                    'type' => 'encounter',
                    'visit_number' => $enc->encounter_number,
                    'patient_id' => $enc->patient_id,
                    'patient_name' => $enc->patient?->full_name ?? 'Unknown',
                    'patient_number' => $enc->patient?->patient_number,
                    'card_number' => $insurance?->card_number,
                    'authorization_no' => $insurance?->nhif_authorization_no,
                    'department' => $enc->department?->name ?? 'OPD',
                    'date' => $enc->started_at?->format('Y-m-d H:i') ?? $enc->created_at->format('Y-m-d H:i'),
                    'status' => $enc->status,
                    'is_completed' => in_array($enc->status, [ClinicalEncounter::STATUS_COMPLETED], true),
                    'existing_claim' => $existingClaim ? [
                        'id' => $existingClaim->id,
                        'claim_number' => $existingClaim->claim_number,
                        'status' => $existingClaim->status,
                    ] : null,
                ];
            });

        // 2. Admissions
        $admissions = Admission::query()
            ->with(['patient.insurances', 'hospital'])
            ->whereHas('patient.insurances', function ($q) {
                $q->whereNotNull('nhif_authorization_no')
                    ->where('nhif_authorization_no', '!=', '');
            })
            ->when($hospitalId, fn ($q) => $q->where('hospital_id', $hospitalId))
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('admission_number', 'like', "%{$search}%")
                        ->orWhereHas('patient', function ($p) use ($search) {
                            $p->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('patient_number', 'like', "%{$search}%")
                                ->orWhereHas('insurances', function ($ins) use ($search) {
                                    $ins->where('card_number', 'like', "%{$search}%")
                                        ->orWhere('nhif_authorization_no', 'like', "%{$search}%");
                                });
                        });
                });
            })
            ->orderByDesc('admitted_at')
            ->limit(20)
            ->get()
            ->map(function (Admission $adm) {
                $insurance = $adm->patientInsurance
                    ?? $adm->patient?->activeNhifInsurance()
                    ?? $adm->patient?->insurances()->whereNotNull('nhif_authorization_no')->latest()->first();

                $existingClaim = NhifClaim::where('admission_id', $adm->id)->where('status', '!=', NhifClaim::STATUS_CANCELLED)->latest()->first();

                return [
                    'id' => $adm->id,
                    'type' => 'admission',
                    'visit_number' => $adm->admission_number,
                    'patient_id' => $adm->patient_id,
                    'patient_name' => $adm->patient?->full_name ?? 'Unknown',
                    'patient_number' => $adm->patient?->patient_number,
                    'card_number' => $insurance?->card_number,
                    'authorization_no' => $insurance?->nhif_authorization_no,
                    'department' => $adm->ward_type ?: 'Inpatient Ward',
                    'date' => $adm->admitted_at?->format('Y-m-d H:i') ?? $adm->created_at->format('Y-m-d H:i'),
                    'status' => $adm->status,
                    'is_completed' => in_array($adm->status, [Admission::STATUS_ACTIVE, Admission::STATUS_DISCHARGED], true),
                    'existing_claim' => $existingClaim ? [
                        'id' => $existingClaim->id,
                        'claim_number' => $existingClaim->claim_number,
                        'status' => $existingClaim->status,
                    ] : null,
                ];
            });

        $results = $encounters->concat($admissions)->values();

        return response()->json([
            'data' => $results,
        ]);
    }
}
