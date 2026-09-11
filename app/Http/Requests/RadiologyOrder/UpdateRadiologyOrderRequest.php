<?php

namespace App\Http\Requests\RadiologyOrder;

use App\Models\RadiologyOrder;
use App\Models\RadiologyProcedure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRadiologyOrderRequest extends FormRequest
{
    /**
     * The 'radiology-orders.update' permission plus hospital/ownership
     * scoping is enforced by RadiologyOrderPolicy in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * procedure_ids, when present, only ever adds new procedures to the
     * order — removing one is cancelItem()'s job, never a plain edit here.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'priority' => ['sometimes', 'string', Rule::in(RadiologyOrder::PRIORITIES)],
            'clinical_indication' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'procedure_ids' => ['sometimes', 'array', 'min:1'],
            'procedure_ids.*' => [
                'distinct',
                'integer',
                Rule::exists(RadiologyProcedure::class, 'id')->where('status', RadiologyProcedure::STATUS_ACTIVE),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'procedure_ids.*.distinct' => 'Duplicate procedures cannot be added to the same radiology order.',
        ];
    }
}
