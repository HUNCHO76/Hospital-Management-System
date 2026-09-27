<?php

namespace App\Services;

use App\Models\LaboratoryResultValue;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestParameter;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Owns the write path for test parameter catalog rows (Batch A).
 *
 * A parameter's code becomes immutable once any result value has been recorded
 * against it — mirrors the same "committed clinical record" rule
 * LaboratoryTestService enforces on LaboratoryTest::code.
 */
class LaboratoryTestParameterService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(LaboratoryTest $test, array $data, Request $request): LaboratoryTestParameter
    {
        $data['code'] = strtoupper(trim($data['code']));

        $parameter = LaboratoryTestParameter::create([
            'laboratory_test_id' => $test->id,
            'active' => true,
            ...$data,
        ]);

        $this->auditLog->record('laboratory_test_parameter_created', $parameter, null, $parameter->only(array_keys($data)), $request);

        return $parameter;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(LaboratoryTestParameter $parameter, array $data, Request $request): LaboratoryTestParameter
    {
        if (array_key_exists('code', $data)) {
            $newCode = strtoupper(trim($data['code']));
            if ($newCode !== $parameter->code && $this->isReferenced($parameter)) {
                throw ValidationException::withMessages([
                    'code' => 'This parameter\'s code cannot be changed because result values have already been recorded against it.',
                ]);
            }
            $data['code'] = $newCode;
        }

        $old = $parameter->only(array_keys($data));
        $parameter->update($data);

        $this->auditLog->record('laboratory_test_parameter_updated', $parameter, $old, $parameter->only(array_keys($data)), $request);

        return $parameter;
    }

    /**
     * @throws ValidationException
     */
    public function delete(LaboratoryTestParameter $parameter, Request $request): void
    {
        if ($this->isReferenced($parameter)) {
            throw ValidationException::withMessages([
                'parameter' => 'This parameter cannot be deleted because result values have already been recorded against it.',
            ]);
        }

        $this->auditLog->record('laboratory_test_parameter_deleted', null, $parameter->toArray(), null, $request);
        $parameter->delete();
    }

    /**
     * Reorders all parameters for a test atomically.
     *
     * @param  list<int>  $orderedIds  parameter ids in desired display order
     */
    public function reorder(LaboratoryTest $test, array $orderedIds, Request $request): void
    {
        foreach ($orderedIds as $i => $id) {
            LaboratoryTestParameter::where('id', $id)
                ->where('laboratory_test_id', $test->id)
                ->update(['display_order' => $i]);
        }

        $this->auditLog->record('laboratory_test_parameters_reordered', $test, null, ['ordered_ids' => $orderedIds], $request);
    }

    /**
     * Whether any result value has been recorded against this parameter.
     * Guards code immutability and deletion.
     */
    private function isReferenced(LaboratoryTestParameter $parameter): bool
    {
        return LaboratoryResultValue::where('laboratory_test_parameter_id', $parameter->id)->exists();
    }
}
