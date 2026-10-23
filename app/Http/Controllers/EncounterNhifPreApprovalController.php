<?php

namespace App\Http\Controllers;

use App\Http\Requests\NHIF\IssuePreApprovalSessionRequest;
use App\Models\ClinicalEncounter;
use App\Models\NhifPreApprovalSession;
use App\Services\NHIF\NhifEncounterPreApprovalService;
use App\Services\NHIF\NhifPreApprovalSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Encounter-scoped NHIF pre-approval session management.
 *
 * All actions redirect back to the clinical encounter show page so the
 * doctor/nurse sees status updates inline. Prices are always fetched
 * server-side from the NHIF API — never accepted from the request.
 */
class EncounterNhifPreApprovalController extends Controller
{
    /**
     * Create and submit a pre-approval session from selected lab/medication items.
     */
    public function store(
        Request $request,
        ClinicalEncounter $clinicalEncounter,
        NhifEncounterPreApprovalService $service,
    ): RedirectResponse {
        $this->authorize('create', NhifPreApprovalSession::class);

        $data = $request->validate([
            'lab_order_item_ids' => ['nullable', 'array'],
            'lab_order_item_ids.*' => ['integer', 'exists:laboratory_order_items,id'],
            'prescription_order_item_ids' => ['nullable', 'array'],
            'prescription_order_item_ids.*' => ['integer', 'exists:prescription_order_items,id'],
            'radiology_order_item_ids' => ['nullable', 'array'],
            'radiology_order_item_ids.*' => ['integer', 'exists:radiology_order_items,id'],
            'clinical_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $labIds = $data['lab_order_item_ids'] ?? [];
        $medIds = $data['prescription_order_item_ids'] ?? [];
        $radIds = $data['radiology_order_item_ids'] ?? [];

        $encounterRedirect = redirect()->route('clinical-encounters.show', $clinicalEncounter);

        if (empty($labIds) && empty($medIds) && empty($radIds)) {
            return $encounterRedirect->with('error', 'Select at least one item for pre-approval.');
        }

        $insurance = $clinicalEncounter->patient?->currentNhifInsurance();

        if (! $insurance) {
            return $encounterRedirect->with('error', 'Patient does not have active NHIF insurance.');
        }

        $practitionerNo = $clinicalEncounter->practitioner?->nhif_practitioner_no;

        try {
            $session = $service->createAndSubmitFromSelection(
                $insurance,
                $clinicalEncounter,
                $labIds,
                $medIds,
                $request,
                $data['clinical_notes'] ?? null,
                $practitionerNo,
                $radIds,
            );
        } catch (ValidationException $e) {
            $firstError = collect($e->errors())->flatten()->first() ?? 'Pre-approval validation failed.';

            return $encounterRedirect->with('error', $firstError)->withErrors($e->errors());
        }

        return redirect()->route('clinical-encounters.show', $clinicalEncounter)
            ->with('success', "Pre-approval session submitted (#{$session->id}).");
    }

    /**
     * Submit a draft session to NHIF.
     */
    public function submit(
        Request $request,
        ClinicalEncounter $clinicalEncounter,
        NhifPreApprovalSession $nhifPreApprovalSession,
        NhifPreApprovalSessionService $service,
    ): RedirectResponse {
        $this->authorize('update', $nhifPreApprovalSession);

        $service->submit($nhifPreApprovalSession, $request);

        return redirect()->route('clinical-encounters.show', $clinicalEncounter)
            ->with('success', 'Services submitted to NHIF.');
    }

    /**
     * Sync approval status from NHIF.
     */
    public function sync(
        Request $request,
        ClinicalEncounter $clinicalEncounter,
        NhifPreApprovalSession $nhifPreApprovalSession,
        NhifPreApprovalSessionService $service,
    ): RedirectResponse {
        $this->authorize('update', $nhifPreApprovalSession);

        $service->syncStatus($nhifPreApprovalSession, $request);

        return redirect()->route('clinical-encounters.show', $clinicalEncounter)
            ->with('success', 'Approval status synced.');
    }

    /**
     * Issue approved services to NHIF after biometric verification.
     */
    public function issue(
        IssuePreApprovalSessionRequest $request,
        ClinicalEncounter $clinicalEncounter,
        NhifPreApprovalSession $nhifPreApprovalSession,
        NhifPreApprovalSessionService $service,
    ): RedirectResponse {
        $this->authorize('update', $nhifPreApprovalSession);

        $session = $service->issue($nhifPreApprovalSession, $request);

        if ($session->status !== NhifPreApprovalSession::STATUS_ISSUED) {
            $message = $session->issue_response_payload['error']
                ?? $session->issue_response_payload['data']['message']
                ?? 'NHIF did not accept the issue request.';

            return redirect()->route('clinical-encounters.show', $clinicalEncounter)
                ->with('error', "Issue failed: {$message}");
        }

        return redirect()->route('clinical-encounters.show', $clinicalEncounter)
            ->with('success', 'Approved services issued.');
    }

    /**
     * Cancel a pre-approval session.
     */
    public function cancel(
        Request $request,
        ClinicalEncounter $clinicalEncounter,
        NhifPreApprovalSession $nhifPreApprovalSession,
        NhifPreApprovalSessionService $service,
    ): RedirectResponse {
        $this->authorize('update', $nhifPreApprovalSession);

        $service->cancel($nhifPreApprovalSession, $request);

        return redirect()->route('clinical-encounters.show', $clinicalEncounter)
            ->with('success', 'Session cancelled.');
    }
}
