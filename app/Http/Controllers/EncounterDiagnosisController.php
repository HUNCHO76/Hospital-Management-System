<?php

namespace App\Http\Controllers;

use App\Http\Requests\Diagnosis\CancelEncounterDiagnosisRequest;
use App\Http\Requests\Diagnosis\ChangeDiagnosisStatusRequest;
use App\Http\Requests\Diagnosis\CorrectEncounterDiagnosisRequest;
use App\Http\Requests\Diagnosis\StoreEncounterDiagnosisRequest;
use App\Http\Requests\Diagnosis\UpdateEncounterDiagnosisRequest;
use App\Models\ClinicalEncounter;
use App\Models\EncounterDiagnosis;
use App\Services\DiagnosisService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Diagnoses are presented as part of the clinical encounter screen, not a
 * standalone page — every action here redirects back to
 * clinical-encounters.show rather than rendering its own Inertia page.
 */
class EncounterDiagnosisController extends Controller
{
    public function store(StoreEncounterDiagnosisRequest $request, ClinicalEncounter $clinicalEncounter, DiagnosisService $service): RedirectResponse
    {
        $this->authorize('create', [EncounterDiagnosis::class, $clinicalEncounter]);

        $service->create($clinicalEncounter, $request->validated(), $request);

        return back()->with('success', 'Diagnosis recorded.');
    }

    public function update(UpdateEncounterDiagnosisRequest $request, ClinicalEncounter $clinicalEncounter, EncounterDiagnosis $diagnosis, DiagnosisService $service): RedirectResponse
    {
        $this->assertBelongsToEncounter($clinicalEncounter, $diagnosis);

        $this->authorize('update', $diagnosis);

        $service->update($diagnosis, $request->validated(), $request);

        return back()->with('success', 'Diagnosis updated.');
    }

    public function primary(Request $request, ClinicalEncounter $clinicalEncounter, EncounterDiagnosis $diagnosis, DiagnosisService $service): RedirectResponse
    {
        $this->assertBelongsToEncounter($clinicalEncounter, $diagnosis);

        $this->authorize('setPrimary', $diagnosis);

        $service->setPrimary($diagnosis, $request);

        return back()->with('success', 'Primary diagnosis updated.');
    }

    public function status(ChangeDiagnosisStatusRequest $request, ClinicalEncounter $clinicalEncounter, EncounterDiagnosis $diagnosis, DiagnosisService $service): RedirectResponse
    {
        $this->assertBelongsToEncounter($clinicalEncounter, $diagnosis);

        $this->authorize('changeStatus', $diagnosis);

        $service->changeStatus($diagnosis, $request->validated()['status'], $request);

        return back()->with('success', 'Diagnosis status updated.');
    }

    public function cancel(CancelEncounterDiagnosisRequest $request, ClinicalEncounter $clinicalEncounter, EncounterDiagnosis $diagnosis, DiagnosisService $service): RedirectResponse
    {
        $this->assertBelongsToEncounter($clinicalEncounter, $diagnosis);

        $this->authorize('cancel', $diagnosis);

        $service->cancel($diagnosis, $request->validated()['cancellation_reason'], $request);

        return back()->with('success', 'Diagnosis cancelled.');
    }

    public function correct(CorrectEncounterDiagnosisRequest $request, ClinicalEncounter $clinicalEncounter, EncounterDiagnosis $diagnosis, DiagnosisService $service): RedirectResponse
    {
        $this->assertBelongsToEncounter($clinicalEncounter, $diagnosis);

        $this->authorize('correct', $diagnosis);

        $data = $request->validated();
        $reason = $data['correction_reason'];
        unset($data['correction_reason']);

        $service->correct($diagnosis, $data, $reason, $request);

        return back()->with('success', 'Diagnosis corrected.');
    }

    /**
     * IDOR guard: a diagnosis accessed through
     * /clinical-encounters/{a}/diagnoses/{diagnosis} must actually belong to
     * encounter {a}, not some other encounter whose id a client substituted
     * in the diagnosis segment.
     */
    protected function assertBelongsToEncounter(ClinicalEncounter $clinicalEncounter, EncounterDiagnosis $diagnosis): void
    {
        abort_if($diagnosis->clinical_encounter_id !== $clinicalEncounter->id, 404);
    }
}
