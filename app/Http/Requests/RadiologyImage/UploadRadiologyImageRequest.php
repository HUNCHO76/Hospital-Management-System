<?php

namespace App\Http\Requests\RadiologyImage;

use App\Services\RadiologyImageService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UploadRadiologyImageRequest extends FormRequest
{
    /**
     * The 'radiology-examination.update' permission plus hospital scoping
     * is enforced by RadiologyAppointmentPolicy in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `mimetypes` validates the file's actual sniffed content type, not
     * just its extension — a `.jpg` that is secretly an executable is
     * rejected, not just a `.exe` renamed to `.jpg`.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1', 'max:'.RadiologyImageService::MAX_FILES_PER_UPLOAD],
            'images.*' => [
                'required',
                'file',
                'mimetypes:'.implode(',', RadiologyImageService::ALLOWED_MIME_TYPES),
                'max:'.RadiologyImageService::MAX_FILE_SIZE_KB,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'images.required' => 'Select at least one image to upload.',
            'images.max' => 'You may upload at most '.RadiologyImageService::MAX_FILES_PER_UPLOAD.' images at a time.',
            'images.*.mimetypes' => 'Only JPG and PNG images are supported.',
            'images.*.max' => 'Each image may not exceed 10MB.',
        ];
    }
}
