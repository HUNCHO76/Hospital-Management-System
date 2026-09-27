<?php

namespace App\Http\Controllers;

use App\Http\Requests\Laboratory\StoreLaboratoryTestParameterRequest;
use App\Http\Requests\Laboratory\UpdateLaboratoryTestParameterRequest;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestParameter;
use App\Services\LaboratoryTestParameterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Manages the parameter catalog nested under a laboratory test.
 * All routes are under laboratory/tests/{laboratoryTest}/parameters/{parameter}.
 * No separate pages — all actions redirect back to the parent test's show page.
 */
class LaboratoryTestParameterController extends Controller
{
    public function store(
        StoreLaboratoryTestParameterRequest $request,
        LaboratoryTest $laboratoryTest,
        LaboratoryTestParameterService $service,
    ): RedirectResponse {
        $this->authorize('create', LaboratoryTestParameter::class);

        $service->create($laboratoryTest, $request->validated(), $request);

        return to_route('laboratory-tests.show', $laboratoryTest)->with('success', 'Parameter added.');
    }

    public function update(
        UpdateLaboratoryTestParameterRequest $request,
        LaboratoryTest $laboratoryTest,
        LaboratoryTestParameter $parameter,
        LaboratoryTestParameterService $service,
    ): RedirectResponse {
        $this->authorize('manage', $parameter);
        $this->assertBelongsToTest($laboratoryTest, $parameter);

        $service->update($parameter, $request->validated(), $request);

        return to_route('laboratory-tests.show', $laboratoryTest)->with('success', 'Parameter updated.');
    }

    public function destroy(
        Request $request,
        LaboratoryTest $laboratoryTest,
        LaboratoryTestParameter $parameter,
        LaboratoryTestParameterService $service,
    ): RedirectResponse {
        $this->authorize('delete', $parameter);
        $this->assertBelongsToTest($laboratoryTest, $parameter);

        $service->delete($parameter, $request);

        return to_route('laboratory-tests.show', $laboratoryTest)->with('success', 'Parameter removed.');
    }

    private function assertBelongsToTest(LaboratoryTest $test, LaboratoryTestParameter $parameter): void
    {
        abort_unless($parameter->laboratory_test_id === $test->id, 404);
    }
}
