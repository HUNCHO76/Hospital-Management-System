<?php

namespace App\Http\Requests\Billing;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CreateChargeRequest extends FormRequest
{
    /**
     * The 'billing-charges.create' permission plus hospital scoping is
     * enforced by ChargePolicy::createInto() in the controller, against the
     * validated hospital_id — never trust a client-supplied hospital_id
     * beyond basic shape here (spec §9/§42).
     *
     * No 'source_type'/'source_id' field here, deliberately: a manually
     * entered charge (this form's only caller) has no real source record to
     * point at — ChargeCaptureService::captureManual() leaves both null for
     * it, the same way ChargeService::create() always did.
     *
     * No 'unit_price'/'description' field here (Phase 7, Step 54, spec §26-
     * 27): a manual charge must select a priced catalog `service_id` — the
     * backend resolves its price via ServicePriceResolver, exactly like an
     * automated capture — never a free-typed amount. 'notes' carries the
     * required reason/reference instead.
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
            'hospital_id' => ['required', 'integer', 'exists:hospitals,id'],
            'patient_id' => ['required', 'integer', 'exists:patients,id'],
            'encounter_id' => ['nullable', 'integer', 'exists:clinical_encounters,id'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'notes' => ['required', 'string', 'max:2000'],
        ];
    }
}
