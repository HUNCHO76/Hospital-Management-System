<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClinicalNote\AmendClinicalNoteRequest;
use App\Http\Requests\ClinicalNote\CancelClinicalNoteRequest;
use App\Http\Requests\ClinicalNote\StoreClinicalNoteRequest;
use App\Http\Requests\ClinicalNote\UpdateClinicalNoteRequest;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalNote;
use App\Services\ClinicalNoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Clinical notes are presented as part of the clinical encounter screen, not
 * a standalone page — every action here redirects back to
 * clinical-encounters.show rather than rendering its own Inertia page.
 */
class ClinicalNoteController extends Controller
{
    public function store(StoreClinicalNoteRequest $request, ClinicalEncounter $clinicalEncounter, ClinicalNoteService $service): RedirectResponse
    {
        $this->authorize('create', [ClinicalNote::class, $clinicalEncounter]);

        $service->create($clinicalEncounter, $request->validated(), $request);

        return back()->with('success', 'Clinical note added.');
    }

    public function update(UpdateClinicalNoteRequest $request, ClinicalEncounter $clinicalEncounter, ClinicalNote $clinicalNote, ClinicalNoteService $service): RedirectResponse
    {
        $this->assertBelongsToEncounter($clinicalEncounter, $clinicalNote);

        $this->authorize('update', $clinicalNote);

        $service->update($clinicalNote, $request->validated(), $request);

        return back()->with('success', 'Clinical note updated.');
    }

    public function sign(Request $request, ClinicalEncounter $clinicalEncounter, ClinicalNote $clinicalNote, ClinicalNoteService $service): RedirectResponse
    {
        $this->assertBelongsToEncounter($clinicalEncounter, $clinicalNote);

        $this->authorize('sign', $clinicalNote);

        $service->sign($clinicalNote, $request);

        return back()->with('success', 'Clinical note signed.');
    }

    public function amend(AmendClinicalNoteRequest $request, ClinicalEncounter $clinicalEncounter, ClinicalNote $clinicalNote, ClinicalNoteService $service): RedirectResponse
    {
        $this->assertBelongsToEncounter($clinicalEncounter, $clinicalNote);

        $this->authorize('amend', $clinicalNote);

        $data = $request->validated();
        $reason = $data['amendment_reason'];
        unset($data['amendment_reason']);

        $service->amend($clinicalNote, $data, $reason, $request);

        return back()->with('success', 'Clinical note amended.');
    }

    public function cancel(CancelClinicalNoteRequest $request, ClinicalEncounter $clinicalEncounter, ClinicalNote $clinicalNote, ClinicalNoteService $service): RedirectResponse
    {
        $this->assertBelongsToEncounter($clinicalEncounter, $clinicalNote);

        $this->authorize('cancel', $clinicalNote);

        $service->cancel($clinicalNote, $request->validated()['cancellation_reason'], $request);

        return back()->with('success', 'Clinical note cancelled.');
    }

    /**
     * IDOR guard: a note accessed through
     * /clinical-encounters/{a}/clinical-notes/{clinicalNote} must actually
     * belong to encounter {a}, not some other encounter whose id a client
     * substituted in the note segment.
     */
    protected function assertBelongsToEncounter(ClinicalEncounter $clinicalEncounter, ClinicalNote $clinicalNote): void
    {
        abort_if($clinicalNote->clinical_encounter_id !== $clinicalEncounter->id, 404);
    }
}
