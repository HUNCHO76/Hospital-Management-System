<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Services\DischargeDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Discharge document generation and viewing (Phase 9, Step 94).
 *
 * Read safety: show() never modifies any record. generate() only writes
 * to discharge_documents — it never touches clinical, financial, NHIF,
 * admission, or bed data.
 */
class DischargeDocumentController extends Controller
{
    /**
     * Show the discharge document page for the given admission.
     *
     * Gates:
     *  - admission.view (AdmissionPolicy) — facility scoping & ownership
     *  - discharge-document.view
     */
    public function show(Request $request, Admission $admission, DischargeDocumentService $service): Response
    {
        $this->authorize('view', $admission);
        abort_unless($request->user()->can('discharge-document.view'), 403);

        $admission->loadMissing([
            'patient:id,patient_number,first_name,middle_name,last_name',
            'hospital:id,name',
            'department:id,name',
        ]);

        $currentDocument = $admission->currentDischargeDocument()
            ->with('generatedBy:id,UserName', 'amendedBy:id,UserName')
            ->first();

        $history = $service->forAdmission($admission);

        return Inertia::render('inpatient/discharge-document', [
            'admission' => $admission,
            'document' => $currentDocument,
            'history' => $history,
            'canGenerate' => $request->user()->can('discharge-document.generate'),
            'canViewBilling' => $request->user()->can('billing.view'),
            'canViewNhif' => $request->user()->can('nhif-claims.view'),
        ]);
    }

    /**
     * Generate (or regenerate) a discharge document for the admission.
     *
     * Each call creates a new version; the previous active document is
     * superseded — never silently overwritten.
     *
     * Gates:
     *  - admission.view (AdmissionPolicy)
     *  - discharge-document.generate
     */
    public function generate(Request $request, Admission $admission, DischargeDocumentService $service): RedirectResponse
    {
        $this->authorize('view', $admission);
        abort_unless($request->user()->can('discharge-document.generate'), 403);

        $isAmendment = $admission->currentDischargeDocument()->exists();

        $validated = $request->validate([
            'amendment_reason' => $isAmendment ? ['required', 'string', 'max:1000'] : ['nullable', 'string', 'max:1000'],
        ]);

        $service->generate($admission, $validated['amendment_reason'] ?? null, $request);

        return redirect()
            ->route('inpatient.discharge-document', $admission)
            ->with('success', $isAmendment ? 'Discharge document amended successfully.' : 'Discharge document generated successfully.');
    }
}
