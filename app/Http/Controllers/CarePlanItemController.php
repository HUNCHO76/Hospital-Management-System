<?php

namespace App\Http\Controllers;

use App\Http\Requests\CarePlan\CancelCarePlanItemRequest;
use App\Http\Requests\CarePlan\ChangeCarePlanItemStatusRequest;
use App\Http\Requests\CarePlan\CorrectCarePlanItemRequest;
use App\Http\Requests\CarePlan\StoreCarePlanItemRequest;
use App\Http\Requests\CarePlan\UpdateCarePlanItemRequest;
use App\Models\CarePlan;
use App\Models\CarePlanItem;
use App\Models\ClinicalEncounter;
use App\Models\Prescription;
use App\Services\CarePlanService;
use Illuminate\Http\RedirectResponse;

/**
 * Treatment items are presented as part of the clinical encounter screen,
 * not a standalone page — every action here redirects back to
 * clinical-encounters.show rather than rendering its own Inertia page.
 */
class CarePlanItemController extends Controller
{
    public function store(StoreCarePlanItemRequest $request, ClinicalEncounter $clinicalEncounter, CarePlanService $service): RedirectResponse
    {
        $carePlan = $this->findCarePlanOrFail($clinicalEncounter);

        $this->authorize('create', [CarePlanItem::class, $carePlan]);

        if ($request->input('type') === CarePlanItem::TYPE_MEDICATION) {
            $this->authorize('create', Prescription::class);
        }

        $service->addItem($carePlan, $request->validated(), $request);

        return back()->with('success', 'Treatment item added.');
    }

    public function update(UpdateCarePlanItemRequest $request, ClinicalEncounter $clinicalEncounter, CarePlanItem $item, CarePlanService $service): RedirectResponse
    {
        $this->assertBelongsToEncounter($clinicalEncounter, $item);

        $this->authorize('update', $item);

        if ($item->type === CarePlanItem::TYPE_MEDICATION && $request->filled('prescription')) {
            $this->authorize('update', Prescription::class);
        }

        $service->updateItem($item, $request->validated(), $request);

        return back()->with('success', 'Treatment item updated.');
    }

    public function status(ChangeCarePlanItemStatusRequest $request, ClinicalEncounter $clinicalEncounter, CarePlanItem $item, CarePlanService $service): RedirectResponse
    {
        $this->assertBelongsToEncounter($clinicalEncounter, $item);

        $this->authorize('changeStatus', $item);

        $service->changeItemStatus($item, $request->validated()['status'], $request);

        return back()->with('success', 'Treatment item status updated.');
    }

    public function cancel(CancelCarePlanItemRequest $request, ClinicalEncounter $clinicalEncounter, CarePlanItem $item, CarePlanService $service): RedirectResponse
    {
        $this->assertBelongsToEncounter($clinicalEncounter, $item);

        $this->authorize('cancel', $item);

        if ($item->type === CarePlanItem::TYPE_MEDICATION) {
            $this->authorize('cancel', Prescription::class);
        }

        $service->cancelItem($item, $request->validated()['cancellation_reason'], $request);

        return back()->with('success', 'Treatment item cancelled.');
    }

    public function correct(CorrectCarePlanItemRequest $request, ClinicalEncounter $clinicalEncounter, CarePlanItem $item, CarePlanService $service): RedirectResponse
    {
        $this->assertBelongsToEncounter($clinicalEncounter, $item);

        $this->authorize('correct', $item);

        $data = $request->validated();
        $reason = $data['correction_reason'];
        unset($data['correction_reason']);

        $service->correctItem($item, $data, $reason, $request);

        return back()->with('success', 'Treatment item corrected.');
    }

    protected function findCarePlanOrFail(ClinicalEncounter $clinicalEncounter): CarePlan
    {
        $carePlan = CarePlan::query()->where('clinical_encounter_id', $clinicalEncounter->id)->first();

        abort_if($carePlan === null, 404, 'No treatment plan exists for this encounter.');

        return $carePlan;
    }

    /**
     * IDOR guard: an item accessed through
     * /clinical-encounters/{a}/care-plan/items/{item} must actually belong
     * to encounter {a}'s own care plan, not some other encounter whose id a
     * client substituted in the URL.
     */
    protected function assertBelongsToEncounter(ClinicalEncounter $clinicalEncounter, CarePlanItem $item): void
    {
        abort_if($item->carePlan->clinical_encounter_id !== $clinicalEncounter->id, 404);
    }
}
