<?php

namespace App\Http\Requests\LaboratoryOrder;

use App\Models\LaboratoryOrder;
use App\Models\LaboratoryTest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLaboratoryOrderRequest extends FormRequest
{
    /**
     * The 'laboratory-orders.update' permission plus hospital/ownership
     * scoping is enforced by LaboratoryOrderPolicy in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * test_ids, when present, only ever adds new tests to the order —
     * removing one is cancelItem()'s job, never a plain edit here.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'priority' => ['sometimes', 'string', Rule::in(LaboratoryOrder::PRIORITIES)],
            'clinical_indication' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'test_ids' => ['sometimes', 'array', 'min:1'],
            'test_ids.*' => [
                'distinct',
                'integer',
                Rule::exists(LaboratoryTest::class, 'id')->where('status', LaboratoryTest::STATUS_ACTIVE),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'test_ids.*.distinct' => 'Duplicate tests cannot be added to the same laboratory order.',
        ];
    }
}
