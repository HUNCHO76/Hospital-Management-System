<?php

namespace App\Http\Requests\ProcedureOrder;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CancelProcedureOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $procedureOrder = $this->route('procedureOrder');

        return $procedureOrder ? (bool) $this->user()?->can('cancel', $procedureOrder) : true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cancellation_reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cancellation_reason.required' => 'A cancellation reason is required to cancel this procedure order.',
        ];
    }
}
