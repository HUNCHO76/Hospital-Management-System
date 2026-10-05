<?php

namespace App\Http\Controllers;

use App\Http\Requests\Inpatient\CorrectMedicationAdministrationRequest;
use App\Http\Requests\Inpatient\RecordMedicationAdministrationRequest;
use App\Models\Admission;
use App\Models\ClinicalEncounter;
use App\Models\MedicationAdministration;
use App\Models\PrescriptionOrder;
use App\Models\PrescriptionOrderItem;
use App\Services\MedicationAdministrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Medication Administration Record (MAR) controller (Phase 9, Step 75).
 *
 * Routes sit inside the inpatient prefix group:
 *   GET  inpatient/{admission}/medications          → worklist()
 *   POST inpatient/{admission}/medications          → store()
 *   POST inpatient/{admission}/medications/{mar}/correct → correct()
 *
 * IDOR: every method asserts the target admission belongs to the authenticated
 * user's facility (hospital-admin scoping) before returning data.
 * Stock deduction is NOT performed here — it was already done at dispensing.
 */
class MedicationAdministrationController extends Controller
{
    /**
     * MAR worklist — all active prescription order items for this admission,
     * with their dispensed quantities and administration history.
     */
    public function worklist(Request $request, Admission $admission): Response
    {
        $this->authorize('view', $admission);

        abort_unless($request->user()->can('inpatient.medications.view'), 403);

        $admission->load([
            'patient:id,patient_number,first_name,last_name',
            'hospital:id,name',
        ]);

        // All inpatient encounters for this admission (for encounter selector)
        $encounters = ClinicalEncounter::query()
            ->where('admission_id', $admission->id)
            ->where('encounter_type', 'inpatient')
            ->orderByDesc('created_at')
            ->get(['id', 'encounter_number', 'status', 'started_at']);

        // All active prescription orders across all inpatient encounters
        $prescriptionOrders = PrescriptionOrder::query()
            ->whereHas('clinicalEncounter', fn ($q) => $q->where('admission_id', $admission->id)->where('encounter_type', 'inpatient'))
            ->where('status', PrescriptionOrder::STATUS_ACTIVE)
            ->with([
                'items' => fn ($q) => $q->where('status', PrescriptionOrderItem::STATUS_ACTIVE),
                'items.medication:id,generic_name,brand_name,code',
                'items.dispensingItems',
                'clinicalEncounter:id,encounter_number,started_at',
            ])
            ->get();

        // Build item-level MAR history (most recent per item, last 24 h)
        $itemIds = $prescriptionOrders->flatMap(fn ($o) => $o->items->pluck('id'))->unique();

        $administrations = MedicationAdministration::query()
            ->whereIn('prescription_order_item_id', $itemIds)
            ->where('admission_id', $admission->id)
            ->with('administeredBy:id,UserName')
            ->orderByDesc('administered_at')
            ->get();

        return Inertia::render('inpatient/medications', [
            'admission' => $admission,
            'encounters' => $encounters,
            'prescriptionOrders' => $prescriptionOrders,
            'administrations' => $administrations,
            'marStatuses' => MedicationAdministration::STATUSES,
            'holdReasons' => MedicationAdministration::HOLD_REASONS,
            'routes' => PrescriptionOrderItem::ROUTES,
            'canAdminister' => $request->user()->can('inpatient.medications.administer'),
            'canHold' => $request->user()->can('inpatient.medications.hold'),
            'canRecordRefusal' => $request->user()->can('inpatient.medications.record-refusal'),
            'canRecordMissed' => $request->user()->can('inpatient.medications.record-missed'),
            'canCorrect' => $request->user()->can('inpatient.medications.correct'),
        ]);
    }

    /**
     * Record a new MAR entry (administer / hold / refuse / miss).
     */
    public function store(
        RecordMedicationAdministrationRequest $request,
        Admission $admission,
        MedicationAdministrationService $service,
    ): RedirectResponse {
        $this->authorize('view', $admission);

        try {
            $mar = $service->record($admission, $request->validated(), $request);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        $label = match ($mar->status) {
            MedicationAdministration::STATUS_ADMINISTERED => 'administered',
            MedicationAdministration::STATUS_HELD => 'held',
            MedicationAdministration::STATUS_REFUSED => 'refused',
            MedicationAdministration::STATUS_MISSED => 'missed',
            default => $mar->status,
        };

        return back()->with('success', "Medication {$label} recorded.");
    }

    /**
     * Correct an existing MAR record.
     */
    public function correct(
        CorrectMedicationAdministrationRequest $request,
        Admission $admission,
        MedicationAdministration $medicationAdministration,
        MedicationAdministrationService $service,
    ): RedirectResponse {
        $this->authorize('view', $admission);

        if ((int) $medicationAdministration->admission_id !== (int) $admission->id) {
            abort(403, 'The MAR record does not belong to this admission.');
        }

        try {
            $service->correct($medicationAdministration, $request->validated(), $request);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        return back()->with('success', 'Administration record corrected.');
    }
}
