<?php

namespace App\Http\Requests\CarePlan;

use App\Models\CarePlanItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeCarePlanItemStatusRequest extends FormRequest
{
    /**
     * The 'treatment.update' permission plus hospital/ownership scoping is
     * enforced by CarePlanItemPolicy in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 'cancelled' is deliberately excluded — cancellation is its own action
     * requiring a mandatory reason.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([CarePlanItem::STATUS_ACTIVE, CarePlanItem::STATUS_COMPLETED])],
        ];
    }
}
