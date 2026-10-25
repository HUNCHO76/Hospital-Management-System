<?php

namespace App\Http\Requests\ProcedureOrder;

use App\Models\ProcedureOrder;
use App\Models\Service;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEncounterProcedureOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', ProcedureOrder::class);
    }

    public function prepareForValidation(): void
    {
        $merge = [];
        if ($this->has('priority') && ! $this->has('urgency')) {
            $merge['urgency'] = $this->input('priority');
        }
        if ($this->has('consent_obtained') && ! $this->has('patient_consent_self_pay')) {
            $merge['patient_consent_self_pay'] = $this->boolean('consent_obtained');
        }
        if ($this->has('emergency_exemption') && ! $this->has('is_emergency_exemption')) {
            $merge['is_emergency_exemption'] = $this->boolean('emergency_exemption');
        }
        if (! empty($merge)) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'service_id' => [
                'nullable',
                'integer',
                Rule::exists(Service::class, 'id')->where('status', Service::STATUS_ACTIVE),
            ],
            'procedure_name' => ['required_without:service_id', 'nullable', 'string', 'max:255'],
            'procedure_type' => ['nullable', 'string', Rule::in(array_keys(ProcedureOrder::TYPES))],
            'urgency' => ['nullable', 'string', Rule::in(['elective', 'urgent', 'emergency', 'routine', 'stat'])],
            'clinical_indication' => ['required', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'scheduled_at' => ['nullable', 'date'],
            'payment_mode' => ['nullable', 'string', Rule::in([
                ProcedureOrder::PAYMENT_MODE_CASH,
                ProcedureOrder::PAYMENT_MODE_NHIF,
                ProcedureOrder::PAYMENT_MODE_INSURANCE,
                ProcedureOrder::PAYMENT_MODE_EXEMPT,
            ])],
            'patient_consent_self_pay' => ['nullable', 'boolean'],
            'send_preapproval' => ['nullable', 'boolean'],
            'requires_preauth' => ['nullable', 'boolean'],
            'is_emergency_exemption' => ['nullable', 'boolean'],
            'exemption_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'service_id.exists' => 'The selected procedure is inactive or not found in the service catalogue.',
            'clinical_indication.required' => 'The clinical indication / medical reason is required for procedure orders.',
        ];
    }
}
