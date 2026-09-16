<?php

namespace App\Http\Requests\Billing;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CreateInvoiceRequest extends FormRequest
{
    /**
     * The 'billing-invoices.create' permission plus hospital scoping is
     * enforced by InvoicePolicy::createInto() in the controller, against
     * the account's own hospital_id — never trust a client-supplied
     * hospital directly (spec §18/§44). `charge_ids` is only ever a
     * starting-point selection: InvoiceService::addCharge() re-validates
     * every one of them server-side regardless (spec §16's own "never rely
     * on the frontend list").
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
            'patient_account_id' => ['required', 'integer', 'exists:patient_accounts,id'],
            'charge_ids' => ['nullable', 'array'],
            'charge_ids.*' => ['integer', 'exists:charges,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
