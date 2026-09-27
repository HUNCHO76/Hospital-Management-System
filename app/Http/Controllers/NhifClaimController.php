<?php

namespace App\Http\Controllers;

use App\Models\Hospital;
use App\Models\NhifBillConfirmation;
use App\Models\NhifClaim;
use App\Models\NhifClaimDocument;
use App\Models\NhifClaimItem;
use App\Models\NhifMonthlySubmission;
use App\Models\NhifUtilization;
use App\Models\PatientInsurance;
use App\Services\NHIF\NhifFolioService;
use App\Services\NHIF\NhifMonthlyClaimService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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

    public function sign(Request $request, NhifClaim $nhifClaim, NhifFolioService $folioService): RedirectResponse
    {
        $data = $request->validate([
            'signature_method' => ['nullable', 'string', 'max:50'],
            'signed_by' => ['nullable', 'string', 'max:100'],
            'signed_by_name' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $folioService->sign($nhifClaim, $data, $request);

        return back()->with('success', 'Folio signed successfully.');
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

    public function monthly(Request $request): Response
    {
        $submissions = NhifMonthlySubmission::with(['hospital', 'submittedBy'])
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (NhifMonthlySubmission $s) => [
                'id' => $s->id,
                'claim_year' => $s->claim_year,
                'claim_month' => $s->claim_month,
                'facility_code' => $s->facility_code,
                'folios_submitted' => $s->folios_submitted,
                'total_amount_claimed' => $s->total_amount_claimed,
                'status' => $s->status,
                'submitted_at' => $s->submitted_at?->toDateTimeString(),
                'hospital' => $s->hospital ? ['name' => $s->hospital->name] : null,
            ]);

        return Inertia::render('nhif/claims/monthly', [
            'submissions' => $submissions,
        ]);
    }

    public function submitMonthly(Request $request, NhifMonthlyClaimService $monthlyService): RedirectResponse
    {
        $data = $request->validate([
            'hospital_id' => ['required', 'integer', 'exists:hospitals,id'],
            'claim_year' => ['required', 'integer', 'min:2000', 'max:2099'],
            'claim_month' => ['required', 'integer', 'min:1', 'max:12'],
            'submission_remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $hospital = Hospital::findOrFail($data['hospital_id']);

        $submission = $monthlyService->submitMonthlyClaim(
            $hospital,
            $data['claim_year'],
            $data['claim_month'],
            $data['submission_remarks'] ?? null,
            $request,
        );

        if ($submission->status === NhifMonthlySubmission::STATUS_SUBMITTED) {
            return redirect()->route('nhif.claims.monthly')
                ->with('success', "Monthly claim for {$data['claim_year']}-{$data['claim_month']} submitted to NHIF.");
        }

        return back()->with('error', 'Monthly claim submission to NHIF failed. Local record saved for retry.');
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
}
