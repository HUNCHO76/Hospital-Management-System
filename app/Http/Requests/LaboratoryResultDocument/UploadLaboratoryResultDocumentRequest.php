<?php

namespace App\Http\Requests\LaboratoryResultDocument;

use Illuminate\Foundation\Http\FormRequest;

class UploadLaboratoryResultDocumentRequest extends FormRequest
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
            'document' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:20480',
            ],
        ];
    }
}
