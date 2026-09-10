<?php

namespace App\Http\Controllers;

use App\Http\Requests\CarePlan\CancelCarePlanRequest;
use App\Http\Requests\CarePlan\CorrectCarePlanRequest;
use App\Http\Requests\CarePlan\StoreCarePlanRequest;
use App\Http\Requests\CarePlan\UpdateCarePlanRequest;
use App\Models\CarePlan;
use App\Models\ClinicalEncounter;
use App\Services\CarePlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The care plan is presented as part of the clinical encounter screen, not a
 * standalone page — every action here redirects back to
 * clinical-encounters.show rather than rendering its own Inertia page.
 */
class CarePlanController extends Controller
{
    public function store(StoreCarePlanRequest $request, ClinicalEncounter $clinicalEncounter, CarePlanService $service): RedirectResponse
    {
        $this->authorize('create', [CarePlan::class, $clinicalEncounter]);

        $service->create($clinicalEncounter, $request->validated(), $request);

        return back()->with('success', 'Treatment plan started.');
    }

    public function update(UpdateCarePlanRequest $request, ClinicalEncounter $clinicalEncounter, CarePlanService $service): RedirectResponse
    {
        $carePlan = $this->findOrFail($clinicalEncounter);

        $this->authorize('update', $carePlan);

        $service->update($carePlan, $request->validated(), $request);

        return back()->with('success', 'Treatment plan updated.');
    }

    public function activate(Request $request, ClinicalEncounter $clinicalEncounter, CarePlanService $service): RedirectResponse
    {
        $carePlan = $this->findOrFail($clinicalEncounter);

        $this->authorize('activate', $carePlan);

        $service->activate($carePlan, $request);

        return back()->with('success', 'Treatment plan activated.');
    }

    public function complete(Request $request, ClinicalEncounter $clinicalEncounter, CarePlanService $service): RedirectResponse
    {
        $carePlan = $this->findOrFail($clinicalEncounter);

        $this->authorize('complete', $carePlan);

        $service->complete($carePlan, $request);

        return back()->with('success', 'Treatment plan completed.');
    }

    public function cancel(CancelCarePlanRequest $request, ClinicalEncounter $clinicalEncounter, CarePlanService $service): RedirectResponse
    {
        $carePlan = $this->findOrFail($clinicalEncounter);

        $this->authorize('cancel', $carePlan);

        $service->cancel($carePlan, $request->validated()['cancellation_reason'], $request);

        return back()->with('success', 'Treatment plan cancelled.');
    }

    public function correct(CorrectCarePlanRequest $request, ClinicalEncounter $clinicalEncounter, CarePlanService $service): RedirectResponse
    {
        $carePlan = $this->findOrFail($clinicalEncounter);

        $this->authorize('correct', $carePlan);

        $data = $request->validated();
        $reason = $data['correction_reason'];
        unset($data['correction_reason']);

        $service->correct($carePlan, $data, $reason, $request);

        return back()->with('success', 'Treatment plan corrected.');
    }

    protected function findOrFail(ClinicalEncounter $clinicalEncounter): CarePlan
    {
        $carePlan = CarePlan::query()->where('clinical_encounter_id', $clinicalEncounter->id)->first();

        abort_if($carePlan === null, 404, 'No treatment plan exists for this encounter.');

        return $carePlan;
    }
}
