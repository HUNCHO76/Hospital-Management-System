<?php

namespace App\Http\Requests\LaboratoryOrder;

use App\Models\LaboratoryOrder;
use App\Models\LaboratoryTest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLaboratoryOrderRequest extends FormRequest
{
    /**
     * The 'laboratory-orders.create' permission plus hospital/ownership
     * scoping is enforced by LaboratoryOrderPolicy::create() in the
     * controller, which receives the route-bound encounter as extra
     * context.
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
            'test_ids' => ['required', 'array', 'min:1'],
            'test_ids.*' => [
                'distinct',
                'integer',
                Rule::exists(LaboratoryTest::class, 'id')->where('status', LaboratoryTest::STATUS_ACTIVE),
            ],
            'priority' => ['nullable', 'string', Rule::in(LaboratoryOrder::PRIORITIES)],
            'clinical_indication' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
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
