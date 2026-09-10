<?php

namespace App\Http\Controllers;

use App\Http\Requests\VitalSign\CorrectVitalSignRequest;
use App\Http\Requests\VitalSign\StoreVitalSignRequest;
use App\Models\ClinicalEncounter;
use App\Models\TriageAssessment;
use App\Models\VitalSign;
use App\Services\VitalSignsService;
use Illuminate\Http\RedirectResponse;

/**
 * Vital signs are presented as part of the clinical encounter screen (spec
 * §38), not a standalone page — every action here redirects back to
 * clinical-encounters.show rather than rendering its own Inertia page.
 */
class VitalSignController extends Controller
{
    public function store(StoreVitalSignRequest $request, ClinicalEncounter $clinicalEncounter, VitalSignsService $service): RedirectResponse
    {
        $this->authorize('view', $clinicalEncounter);
        $this->authorize('create', VitalSign::class);

        $triage = TriageAssessment::query()->where('clinical_encounter_id', $clinicalEncounter->id)->first();

        abort_if($triage === null, 404, 'No triage assessment exists for this encounter.');

        $service->record($triage, $request->validated(), $request);

        return back()->with('success', 'Vital signs recorded.');
    }

    public function correct(CorrectVitalSignRequest $request, ClinicalEncounter $clinicalEncounter, VitalSign $vitalSign, VitalSignsService $service): RedirectResponse
    {
        abort_if($vitalSign->clinical_encounter_id !== $clinicalEncounter->id, 404);

        $this->authorize('correct', $vitalSign);

        $validated = $request->validated();
        $reason = $validated['correction_reason'];
        unset($validated['correction_reason']);

        $service->correct($vitalSign, $validated, $reason, $request);

        return back()->with('success', 'Vital sign correction recorded.');
    }
}
