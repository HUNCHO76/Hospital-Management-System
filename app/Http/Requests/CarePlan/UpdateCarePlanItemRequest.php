<?php

namespace App\Http\Requests\CarePlan;

use App\Models\CarePlanItem;
use App\Models\Medication;
use App\Models\Prescription;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCarePlanItemRequest extends FormRequest
{
    /**
     * The 'treatment.update' permission plus hospital/ownership scoping is
     * enforced by CarePlanItemPolicy in the controller. The additional
     * 'prescription.update' gate for medication items is checked separately
     * in the controller.
     *
     * type is deliberately not editable here — changing what an item is
     * would invalidate its referral/prescription fields; cancel and re-add
     * instead.
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
        return [
            'description' => ['sometimes', 'string', 'max:2000'],
            'priority' => ['nullable', 'string', Rule::in(CarePlanItem::PRIORITIES)],
            'encounter_diagnosis_id' => ['nullable', 'integer', 'exists:encounter_diagnoses,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'instructions' => ['nullable', 'string', 'max:2000'],

            'referred_to_department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'referred_to_practitioner_id' => ['nullable', 'integer', 'exists:practitioners,id'],

            'prescription' => ['nullable', 'array'],
            'prescription.medication_id' => [
                'sometimes',
                'integer',
                Rule::exists(Medication::class, 'id')->where('status', Medication::STATUS_ACTIVE),
            ],
            'prescription.strength' => ['nullable', 'string', 'max:100'],
            'prescription.dose' => ['nullable', 'string', 'max:100'],
            'prescription.route' => ['nullable', 'string', Rule::in(Prescription::ROUTES)],
            'prescription.frequency' => ['nullable', 'string', 'max:100'],
            'prescription.duration' => ['nullable', 'string', 'max:100'],
            'prescription.quantity' => ['nullable', 'string', 'max:100'],
            'prescription.instructions' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
