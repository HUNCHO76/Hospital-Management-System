<?php

namespace App\Services;

use App\Models\Medication;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Owns the medication catalog's write path (Phase 6, Step 45) — mirrors
 * LaboratoryTestService/RadiologyProcedureService's shape exactly. Business
 * rule this class alone enforces: `code` becomes immutable once any
 * Prescription references the medication (the same "committed clinical
 * record" mutability pattern LaboratoryTest/RadiologyProcedure apply to
 * catalog identity).
 */
class MedicationService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, Request $request): Medication
    {
        $data['code'] = strtoupper(trim($data['code']));

        $medication = Medication::create([
            'status' => Medication::STATUS_ACTIVE,
            ...$data,
            'created_by' => $request->user()->id,
        ]);

        $this->auditLog->record('medication_created', $medication, null, $medication->only(array_keys($data)), $request);

        return $medication;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(Medication $medication, array $data, Request $request): Medication
    {
        if (array_key_exists('code', $data) && strtoupper(trim($data['code'])) !== $medication->code && $medication->isReferenced()) {
            throw ValidationException::withMessages([
                'code' => 'This medication\'s code cannot be changed because it has already been referenced by a prescription.',
            ]);
        }

        if (array_key_exists('code', $data)) {
            $data['code'] = strtoupper(trim($data['code']));
        }

        $oldValues = $medication->only(array_keys($data));

        $data['updated_by'] = $request->user()->id;
        $medication->update($data);

        $this->auditLog->record('medication_updated', $medication, $oldValues, $medication->only(array_keys($data)), $request);

        $configurationFields = ['generic_name', 'brand_name', 'strength', 'form', 'route', 'unit', 'category', 'description'];
        if (array_intersect($configurationFields, array_keys($data)) !== []) {
            $this->auditLog->record('medication_configuration_changed', $medication, null, $medication->only($configurationFields), $request);
        }

        if (array_key_exists('price', $data)) {
            $this->auditLog->record('medication_service_mapping_changed', $medication, null, ['price' => $medication->price], $request);
        }

        if (array_key_exists('nhif_code', $data)) {
            $this->auditLog->record('medication_nhif_mapping_changed', $medication, null, ['nhif_code' => $medication->nhif_code], $request);
        }

        return $medication;
    }

    public function activate(Medication $medication, Request $request): Medication
    {
        $oldValues = $medication->only(['status']);

        $medication->update(['status' => Medication::STATUS_ACTIVE, 'updated_by' => $request->user()->id]);

        $this->auditLog->record('medication_activated', $medication, $oldValues, $medication->only(['status']), $request);

        return $medication;
    }

    public function deactivate(Medication $medication, Request $request): Medication
    {
        $oldValues = $medication->only(['status']);

        $medication->update(['status' => Medication::STATUS_INACTIVE, 'updated_by' => $request->user()->id]);

        $this->auditLog->record('medication_deactivated', $medication, $oldValues, $medication->only(['status']), $request);

        return $medication;
    }
}
