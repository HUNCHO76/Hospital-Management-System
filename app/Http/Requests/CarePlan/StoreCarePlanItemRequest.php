<?php

namespace App\Http\Requests\CarePlan;

use App\Models\CarePlanItem;
use App\Models\Medication;
use App\Models\Prescription;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCarePlanItemRequest extends FormRequest
{
    /**
     * The 'treatment.create' permission plus hospital/ownership scoping is
     * enforced by CarePlanItemPolicy in the controller. The additional
     * 'prescription.create' gate for medication items is checked separately
     * in the controller (spec §32's genuinely separate permission).
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
            'type' => ['required', 'string', Rule::in(CarePlanItem::TYPES)],
            'description' => ['required', 'string', 'max:2000'],
            'priority' => ['nullable', 'string', Rule::in(CarePlanItem::PRIORITIES)],
            'encounter_diagnosis_id' => ['nullable', 'integer', 'exists:encounter_diagnoses,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'instructions' => ['nullable', 'string', 'max:2000'],

            'referred_to_department_id' => ['required_if:type,'.CarePlanItem::TYPE_REFERRAL, 'nullable', 'integer', 'exists:departments,id'],
            'referred_to_practitioner_id' => ['nullable', 'integer', 'exists:practitioners,id'],

            'prescription' => ['required_if:type,'.CarePlanItem::TYPE_MEDICATION, 'nullable', 'array'],
            'prescription.medication_id' => [
                'required_if:type,'.CarePlanItem::TYPE_MEDICATION,
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
