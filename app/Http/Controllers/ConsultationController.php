<?php

namespace App\Http\Controllers;

use App\Http\Requests\Consultation\CancelConsultationRequest;
use App\Http\Requests\Consultation\CorrectConsultationRequest;
use App\Http\Requests\Consultation\StoreConsultationRequest;
use App\Http\Requests\Consultation\UpdateConsultationRequest;
use App\Models\ClinicalEncounter;
use App\Models\Consultation;
use App\Services\ConsultationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Consultation is presented as part of the clinical encounter screen (spec
 * §26/28), not a standalone page — every action here redirects back to
 * clinical-encounters.show rather than rendering its own Inertia page.
 */
class ConsultationController extends Controller
{
    public function store(StoreConsultationRequest $request, ClinicalEncounter $clinicalEncounter, ConsultationService $service): RedirectResponse
    {
        $this->authorize('create', Consultation::class);

        $service->create($clinicalEncounter, $request);

        return back()->with('success', 'Consultation started.');
    }

    public function update(UpdateConsultationRequest $request, ClinicalEncounter $clinicalEncounter, ConsultationService $service): RedirectResponse
    {
        $consultation = $this->findOrFail($clinicalEncounter);

        $this->authorize('update', $consultation);

        $service->update($consultation, $request->validated(), $request);

        return back()->with('success', 'Consultation updated.');
    }

    public function start(Request $request, ClinicalEncounter $clinicalEncounter, ConsultationService $service): RedirectResponse
    {
        $consultation = $this->findOrFail($clinicalEncounter);

        $this->authorize('start', $consultation);

        $service->start($consultation, $request);

        return back()->with('success', 'Consultation in progress.');
    }

    public function complete(Request $request, ClinicalEncounter $clinicalEncounter, ConsultationService $service): RedirectResponse
    {
        $consultation = $this->findOrFail($clinicalEncounter);

        $this->authorize('complete', $consultation);

        $service->complete($consultation, $request);

        return back()->with('success', 'Consultation completed.');
    }

    public function cancel(CancelConsultationRequest $request, ClinicalEncounter $clinicalEncounter, ConsultationService $service): RedirectResponse
    {
        $consultation = $this->findOrFail($clinicalEncounter);

        $this->authorize('cancel', $consultation);

        $service->cancel($consultation, $request->validated()['cancellation_reason'], $request);

        return back()->with('success', 'Consultation cancelled.');
    }

    public function correct(CorrectConsultationRequest $request, ClinicalEncounter $clinicalEncounter, ConsultationService $service): RedirectResponse
    {
        $consultation = $this->findOrFail($clinicalEncounter);

        $this->authorize('correct', $consultation);

        $data = $request->validated();
        $reason = $data['correction_reason'];
        unset($data['correction_reason']);

        $service->correct($consultation, $data, $reason, $request);

        return back()->with('success', 'Consultation corrected.');
    }

    protected function findOrFail(ClinicalEncounter $clinicalEncounter): Consultation
    {
        $consultation = Consultation::query()->where('clinical_encounter_id', $clinicalEncounter->id)->first();

        abort_if($consultation === null, 404, 'No consultation exists for this encounter.');

        return $consultation;
    }
}
