<?php

namespace App\Http\Requests\Inpatient;

use App\Models\DeliveryRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeliveryRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inpatient-nursing.view') || $this->user()->can('maternity.delivery-record');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'delivered_at' => ['required', 'date'],
            'delivery_mode' => ['required', 'string', Rule::in(array_keys(DeliveryRecord::MODES))],
            'indication' => ['nullable', 'string', 'max:255'],
            'conducted_by' => ['nullable', 'integer', 'exists:users,id'],
            'assisted_by' => ['nullable', 'integer', 'exists:users,id'],
            'placenta_delivered_at' => ['nullable', 'date'],
            'placenta_mode' => ['nullable', 'string', 'max:40'],
            'placenta_complete' => ['boolean'],
            'blood_loss_ml' => ['required', 'integer', 'min:0', 'max:5000'],
            'postpartum_hemorrhage' => ['boolean'],
            'perineal_status' => ['nullable', 'string', Rule::in(array_keys(DeliveryRecord::PERINEAL_STATUSES))],
            'suture_materials_used' => ['nullable', 'string', 'max:255'],
            'oxytocic_given' => ['nullable', 'string', 'max:100'],
            'baby_outcome' => ['required', 'string', Rule::in(array_keys(DeliveryRecord::BABY_OUTCOMES))],
            'baby_gender' => ['required', 'string', Rule::in(['male', 'female', 'ambiguous'])],
            'birth_weight_grams' => ['nullable', 'integer', 'between:200,8000'],
            'birth_length_cm' => ['nullable', 'numeric', 'between:15,70'],
            'head_circumference_cm' => ['nullable', 'numeric', 'between:15,50'],
            'apgar_1min' => ['nullable', 'integer', 'between:0,10'],
            'apgar_5min' => ['nullable', 'integer', 'between:0,10'],
            'apgar_10min' => ['nullable', 'integer', 'between:0,10'],
            'resuscitation_required' => ['boolean'],
            'resuscitation_notes' => ['nullable', 'string', 'max:1000'],
            'vitamin_k_given' => ['boolean'],
            'eye_ointment_given' => ['boolean'],
            'bcg_given' => ['boolean'],
            'polio_0_given' => ['boolean'],
            'baby_patient_id' => ['nullable', 'integer', 'exists:patients,id'],
            'is_emergency' => ['nullable', 'boolean'],
            'emergency_reason' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
