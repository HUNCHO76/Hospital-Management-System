<?php

namespace App\Http\Requests\Medication;

use App\Models\Medication;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMedicationRequest extends FormRequest
{
    /**
     * The 'medications.update' permission is enforced by
     * MedicationPolicy::update() in the controller. `code` is accepted here
     * (sometimes-unique, ignoring this record) so an as-yet-unreferenced
     * medication's code can still be corrected — once a Prescription
     * references it, MedicationService::update() itself rejects any change
     * to it, which is the real, authoritative guard.
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
        /** @var Medication $medication */
        $medication = $this->route('medication');

        return [
            'code' => ['sometimes', 'string', 'max:50', Rule::unique(Medication::class, 'code')->ignore($medication->id)],
            'generic_name' => ['sometimes', 'string', 'max:255'],
            'brand_name' => ['nullable', 'string', 'max:255'],
            'strength' => ['nullable', 'string', 'max:100'],
            'form' => ['nullable', 'string', Rule::in(Medication::FORMS)],
            'route' => ['nullable', 'string', Rule::in(Medication::ROUTES)],
            'unit' => ['nullable', 'string', 'max:50'],
            'category' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'nhif_code' => ['nullable', 'string', 'max:50'],
            'price' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
