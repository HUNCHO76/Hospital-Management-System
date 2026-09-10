<?php

namespace App\Services;

use App\Models\LaboratoryBench;
use Illuminate\Http\Request;

/**
 * Mirrors LaboratoryTestCategoryService's shape exactly. No delete() —
 * benches are deactivated, never deleted, the same no-hard-delete
 * precedent every other clinical catalog in this app follows.
 */
class LaboratoryBenchService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, Request $request): LaboratoryBench
    {
        $bench = LaboratoryBench::create([
            'status' => LaboratoryBench::STATUS_ACTIVE,
            ...$data,
            'created_by' => $request->user()->id,
        ]);

        $this->auditLog->record('laboratory_bench_created', $bench, null, $bench->only(array_keys($data)), $request);

        return $bench;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(LaboratoryBench $bench, array $data, Request $request): LaboratoryBench
    {
        $oldValues = $bench->only(array_keys($data));

        $data['updated_by'] = $request->user()->id;
        $bench->update($data);

        $this->auditLog->record('laboratory_bench_updated', $bench, $oldValues, $bench->only(array_keys($data)), $request);

        return $bench;
    }

    public function activate(LaboratoryBench $bench, Request $request): LaboratoryBench
    {
        $oldValues = $bench->only(['status']);

        $bench->update(['status' => LaboratoryBench::STATUS_ACTIVE, 'updated_by' => $request->user()->id]);

        $this->auditLog->record('laboratory_bench_activated', $bench, $oldValues, $bench->only(['status']), $request);

        return $bench;
    }

    public function deactivate(LaboratoryBench $bench, Request $request): LaboratoryBench
    {
        $oldValues = $bench->only(['status']);

        $bench->update(['status' => LaboratoryBench::STATUS_INACTIVE, 'updated_by' => $request->user()->id]);

        $this->auditLog->record('laboratory_bench_deactivated', $bench, $oldValues, $bench->only(['status']), $request);

        return $bench;
    }
}
