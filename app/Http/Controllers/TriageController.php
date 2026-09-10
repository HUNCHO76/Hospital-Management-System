<?php

namespace App\Http\Controllers;

use App\Http\Requests\Triage\CancelTriageRequest;
use App\Http\Requests\Triage\StoreTriageRequest;
use App\Http\Requests\Triage\UpdateTriageRequest;
use App\Models\ClinicalEncounter;
use App\Models\TriageAssessment;
use App\Services\TriageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Triage is presented as part of the clinical encounter screen (spec §38),
 * not a standalone page — every action here redirects back to
 * clinical-encounters.show rather than rendering its own Inertia page.
 */
class TriageController extends Controller
{
    public function store(StoreTriageRequest $request, ClinicalEncounter $clinicalEncounter, TriageService $service): RedirectResponse
    {
        $this->authorize('create', TriageAssessment::class);

        $service->create($clinicalEncounter, $request);

        return back()->with('success', 'Triage assessment started.');
    }

    public function update(UpdateTriageRequest $request, ClinicalEncounter $clinicalEncounter, TriageService $service): RedirectResponse
    {
        $triage = $this->findOrFail($clinicalEncounter);

        $this->authorize('update', $triage);

        $service->update($triage, $request->validated(), $request);

        return back()->with('success', 'Triage assessment updated.');
    }

    public function start(Request $request, ClinicalEncounter $clinicalEncounter, TriageService $service): RedirectResponse
    {
        $triage = $this->findOrFail($clinicalEncounter);

        $this->authorize('start', $triage);

        $service->start($triage, $request);

        return back()->with('success', 'Triage assessment in progress.');
    }

    public function complete(Request $request, ClinicalEncounter $clinicalEncounter, TriageService $service): RedirectResponse
    {
        $triage = $this->findOrFail($clinicalEncounter);

        $this->authorize('complete', $triage);

        $service->complete($triage, $request);

        return back()->with('success', 'Triage assessment completed — patient ready for consultation.');
    }

    public function cancel(CancelTriageRequest $request, ClinicalEncounter $clinicalEncounter, TriageService $service): RedirectResponse
    {
        $triage = $this->findOrFail($clinicalEncounter);

        $this->authorize('cancel', $triage);

        $service->cancel($triage, $request->validated()['cancellation_reason'], $request);

        return back()->with('success', 'Triage assessment cancelled.');
    }

    protected function findOrFail(ClinicalEncounter $clinicalEncounter): TriageAssessment
    {
        $triage = TriageAssessment::query()->where('clinical_encounter_id', $clinicalEncounter->id)->first();

        abort_if($triage === null, 404, 'No triage assessment exists for this encounter.');

        return $triage;
    }
}
