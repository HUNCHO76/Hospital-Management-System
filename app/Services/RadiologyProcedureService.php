<?php

namespace App\Services;

use App\Models\RadiologyProcedure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Owns the radiology procedure catalog's write path (Phase 5, Step 37) —
 * mirrors LaboratoryTestService's shape exactly. Business rule this class
 * alone enforces: `code` becomes immutable once any RadiologyOrder
 * references the procedure (the same "committed clinical record"
 * mutability pattern LaboratoryTest applies to catalog identity).
 */
class RadiologyProcedureService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, Request $request): RadiologyProcedure
    {
        $data['code'] = strtoupper(trim($data['code']));

        $procedure = RadiologyProcedure::create([
            'status' => RadiologyProcedure::STATUS_ACTIVE,
            ...$data,
            'created_by' => $request->user()->id,
        ]);

        $this->auditLog->record('radiology_procedure_created', $procedure, null, $procedure->only(array_keys($data)), $request);

        return $procedure;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(RadiologyProcedure $procedure, array $data, Request $request): RadiologyProcedure
    {
        if (array_key_exists('code', $data) && strtoupper(trim($data['code'])) !== $procedure->code && $procedure->isReferenced()) {
            throw ValidationException::withMessages([
                'code' => 'This procedure\'s code cannot be changed because it has already been referenced by a radiology order.',
            ]);
        }

        if (array_key_exists('code', $data)) {
            $data['code'] = strtoupper(trim($data['code']));
        }

        $oldValues = $procedure->only(array_keys($data));

        $data['updated_by'] = $request->user()->id;
        $procedure->update($data);

        $this->auditLog->record('radiology_procedure_updated', $procedure, $oldValues, $procedure->only(array_keys($data)), $request);

        $configurationFields = [
            'modality', 'body_sites', 'laterality', 'contrast_requirement', 'contrast_type',
            'preparation_type', 'preparation_instructions', 'turnaround_time_minutes', 'sort_order',
        ];
        if (array_intersect($configurationFields, array_keys($data)) !== []) {
            $this->auditLog->record('radiology_procedure_configuration_changed', $procedure, null, $procedure->only($configurationFields), $request);
        }

        if (array_key_exists('price', $data)) {
            $this->auditLog->record('radiology_procedure_service_mapping_changed', $procedure, null, ['price' => $procedure->price], $request);
        }

        if (array_key_exists('nhif_code', $data)) {
            $this->auditLog->record('radiology_procedure_nhif_mapping_changed', $procedure, null, ['nhif_code' => $procedure->nhif_code], $request);
        }

        return $procedure;
    }

    public function activate(RadiologyProcedure $procedure, Request $request): RadiologyProcedure
    {
        $oldValues = $procedure->only(['status']);

        $procedure->update(['status' => RadiologyProcedure::STATUS_ACTIVE, 'updated_by' => $request->user()->id]);

        $this->auditLog->record('radiology_procedure_activated', $procedure, $oldValues, $procedure->only(['status']), $request);

        return $procedure;
    }

    public function deactivate(RadiologyProcedure $procedure, Request $request): RadiologyProcedure
    {
        $oldValues = $procedure->only(['status']);

        $procedure->update(['status' => RadiologyProcedure::STATUS_INACTIVE, 'updated_by' => $request->user()->id]);

        $this->auditLog->record('radiology_procedure_deactivated', $procedure, $oldValues, $procedure->only(['status']), $request);

        return $procedure;
    }
}
