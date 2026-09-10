<?php

namespace App\Services;

use App\Models\LaboratoryTest;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Owns the laboratory test catalog's write path (Phase 4, Step 29) — mirrors
 * DepartmentService's shape. Business rule this class alone enforces:
 * `code` becomes immutable once any LaboratoryOrder references the test
 * (spec §6/§34's "committed clinical record" mutability pattern, applied to
 * catalog identity rather than a clinical event).
 */
class LaboratoryTestService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, Request $request): LaboratoryTest
    {
        $data['code'] = strtoupper(trim($data['code']));

        $test = LaboratoryTest::create([
            'status' => LaboratoryTest::STATUS_ACTIVE,
            ...$data,
            'created_by' => $request->user()->id,
        ]);

        $this->auditLog->record('laboratory_test_created', $test, null, $test->only(array_keys($data)), $request);

        return $test;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(LaboratoryTest $test, array $data, Request $request): LaboratoryTest
    {
        if (array_key_exists('code', $data) && strtoupper(trim($data['code'])) !== $test->code && $test->isReferenced()) {
            throw ValidationException::withMessages([
                'code' => 'This test\'s code cannot be changed because it has already been referenced by a laboratory order.',
            ]);
        }

        if (array_key_exists('code', $data)) {
            $data['code'] = strtoupper(trim($data['code']));
        }

        $oldValues = $test->only(array_keys($data));

        $data['updated_by'] = $request->user()->id;
        $test->update($data);

        $this->auditLog->record('laboratory_test_updated', $test, $oldValues, $test->only(array_keys($data)), $request);

        $configurationFields = ['specimen_type', 'specimen_container', 'collection_notes', 'result_type', 'unit', 'reference_range', 'result_options', 'turnaround_time_minutes', 'default_priority'];
        if (array_intersect($configurationFields, array_keys($data)) !== []) {
            $this->auditLog->record('laboratory_test_configuration_changed', $test, null, $test->only($configurationFields), $request);
        }

        if (array_key_exists('price', $data)) {
            $this->auditLog->record('laboratory_test_service_mapping_changed', $test, null, ['price' => $test->price], $request);
        }

        if (array_key_exists('nhif_code', $data)) {
            $this->auditLog->record('laboratory_test_nhif_mapping_changed', $test, null, ['nhif_code' => $test->nhif_code], $request);
        }

        return $test;
    }

    public function activate(LaboratoryTest $test, Request $request): LaboratoryTest
    {
        $oldValues = $test->only(['status']);

        $test->update(['status' => LaboratoryTest::STATUS_ACTIVE, 'updated_by' => $request->user()->id]);

        $this->auditLog->record('laboratory_test_activated', $test, $oldValues, $test->only(['status']), $request);

        return $test;
    }

    public function deactivate(LaboratoryTest $test, Request $request): LaboratoryTest
    {
        $oldValues = $test->only(['status']);

        $test->update(['status' => LaboratoryTest::STATUS_INACTIVE, 'updated_by' => $request->user()->id]);

        $this->auditLog->record('laboratory_test_deactivated', $test, $oldValues, $test->only(['status']), $request);

        return $test;
    }
}
