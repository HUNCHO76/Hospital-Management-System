<?php

namespace App\Http\Requests\Radiology;

use App\Models\RadiologyProcedure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRadiologyProcedureRequest extends FormRequest
{
    /**
     * The 'radiology-procedures.update' permission is enforced by
     * RadiologyProcedurePolicy::update() in the controller. `code` is
     * accepted here (sometimes-unique, ignoring this record) so an
     * as-yet-unreferenced procedure's code can still be corrected — once a
     * RadiologyOrder references it, RadiologyProcedureService::update()
     * itself rejects any change to it, which is the real, authoritative
     * guard.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var RadiologyProcedure $procedure */
        $procedure = $this->route('radiologyProcedure');

        return [
            'code' => ['sometimes', 'string', 'max:50', Rule::unique(RadiologyProcedure::class, 'code')->ignore($procedure->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'modality' => ['nullable', 'string', Rule::in(RadiologyProcedure::MODALITIES)],
            'body_sites' => ['nullable', 'array'],
            'body_sites.*' => ['string', Rule::in(RadiologyProcedure::BODY_SITES)],
            'laterality' => ['nullable', 'string', Rule::in(RadiologyProcedure::LATERALITY_OPTIONS)],
            'contrast_requirement' => ['nullable', 'string', Rule::in(RadiologyProcedure::CONTRAST_REQUIREMENTS)],
            'contrast_type' => ['nullable', 'string', Rule::in(RadiologyProcedure::CONTRAST_TYPES)],
            'preparation_type' => ['nullable', 'string', Rule::in(RadiologyProcedure::PREPARATION_TYPES)],
            'preparation_instructions' => ['nullable', 'string', 'max:2000'],
            'turnaround_time_minutes' => ['nullable', 'integer', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'nhif_code' => ['nullable', 'string', 'max:50'],
            'price' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
