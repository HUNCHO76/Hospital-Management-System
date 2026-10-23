<?php

namespace App\Http\Requests\Billing;

use App\Models\Payment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Structural validation only (spec §57.29) — never financial authorization.
 * `amount` is checked here only for being a well-formed, positive number;
 * whether it actually fits the invoice's own outstanding balance is
 * PaymentService::createPayment()'s own job, revalidated under a lock
 * regardless of what this request allowed.
 */
class StorePaymentRequest extends FormRequest
{
    /**
     * The 'billing-payments.create' permission plus hospital scoping is
     * enforced by PaymentPolicy::createInto() in the controller, against
     * the invoice's own hospital_id.
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
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99'],
            'payment_date' => ['nullable', 'date', 'before_or_equal:today'],
            'payment_method' => ['required', 'string', Rule::in(Payment::METHODS)],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ];
    }
}
