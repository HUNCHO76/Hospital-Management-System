<?php

namespace App\Http\Requests\ProcedureOrder;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExecuteProcedureOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $procedureOrder = $this->route('procedureOrder');

        return $procedureOrder ? (bool) $this->user()?->can('execute', $procedureOrder) : true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'performed_at' => ['nullable', 'date'],
            'performer_id' => ['nullable', 'integer', Rule::exists(User::class, 'id')],
            'findings' => ['nullable', 'string', 'max:4000'],
            'results' => ['nullable', 'string', 'max:4000'],
            'procedure_notes' => ['required_without:findings', 'nullable', 'string', 'max:4000'],
            'complications' => ['nullable', 'string', 'max:2000'],
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
            'procedure_notes.required' => 'Clinical procedure execution notes are required.',
        ];
    }
}
