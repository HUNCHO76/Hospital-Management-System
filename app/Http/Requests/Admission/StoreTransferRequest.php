<?php

namespace App\Http\Requests\Admission;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'to_hospital_id' => ['required', 'integer', 'exists:hospitals,id'],
            'to_ward_type' => ['nullable', 'string', 'max:100'],
            'to_room_type' => ['nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:1000'],
            'charges_per_day' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
