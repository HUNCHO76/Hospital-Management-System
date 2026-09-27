<?php

namespace App\Http\Controllers;

use App\Http\Requests\Laboratory\StoreLaboratoryReferenceRangeRequest;
use App\Http\Requests\Laboratory\UpdateLaboratoryReferenceRangeRequest;
use App\Models\LaboratoryReferenceRange;
use App\Models\LaboratoryTestParameter;
use App\Services\LaboratoryReferenceRangeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Manages reference ranges nested under a laboratory test parameter.
 * All actions redirect back to the parent test's show page.
 */
class LaboratoryReferenceRangeController extends Controller
{
    public function store(
        StoreLaboratoryReferenceRangeRequest $request,
        LaboratoryTestParameter $parameter,
        LaboratoryReferenceRangeService $service,
    ): RedirectResponse {
        $this->authorize('manage', $parameter);

        $service->create($parameter, $request->validated(), $request);

        return to_route('laboratory-tests.show', $parameter->laboratory_test_id)
            ->with('success', 'Reference range added.');
    }

    public function update(
        UpdateLaboratoryReferenceRangeRequest $request,
        LaboratoryTestParameter $parameter,
        LaboratoryReferenceRange $range,
        LaboratoryReferenceRangeService $service,
    ): RedirectResponse {
        $this->authorize('manage', $parameter);
        $this->assertBelongsToParameter($parameter, $range);

        $service->update($range, $request->validated(), $request);

        return to_route('laboratory-tests.show', $parameter->laboratory_test_id)
            ->with('success', 'Reference range updated.');
    }

    public function destroy(
        Request $request,
        LaboratoryTestParameter $parameter,
        LaboratoryReferenceRange $range,
        LaboratoryReferenceRangeService $service,
    ): RedirectResponse {
        $this->authorize('manage', $parameter);
        $this->assertBelongsToParameter($parameter, $range);

        $service->delete($range, $request);

        return to_route('laboratory-tests.show', $parameter->laboratory_test_id)
            ->with('success', 'Reference range removed.');
    }

    private function assertBelongsToParameter(LaboratoryTestParameter $parameter, LaboratoryReferenceRange $range): void
    {
        abort_unless($range->laboratory_test_parameter_id === $parameter->id, 404);
    }
}
