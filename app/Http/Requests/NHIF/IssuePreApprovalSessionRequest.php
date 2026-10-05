<?php

namespace App\Http\Requests\NHIF;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the biometric verification data required by NHIF IssueRequestServices.
 *
 *   NONE        → fpCode = null, imageData = null
 *   FINGERPRINT → fpCode (finger code) + imageData (base64) required
 *   FACIAL      → imageData (base64) required, fpCode = null
 */
class IssuePreApprovalSessionRequest extends FormRequest
{
    public const METHOD_FINGERPRINT = 'FINGERPRINT';

    public const METHOD_NONE = 'NONE';

    public const METHOD_FACIAL = 'FACIAL';

    /** @var list<string> */
    public const BIOMETRIC_METHODS = [self::METHOD_FINGERPRINT, self::METHOD_NONE, self::METHOD_FACIAL];

    /** @var list<string> */
    public const FINGER_CODES = ['R1', 'R2', 'R3', 'R4', 'RTH', 'L1', 'L2', 'L3', 'L4', 'LTH'];

    /**
     * Authorization is enforced by NhifPreApprovalSessionPolicy in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise inputs: upper-case the method/finger code, turn empty strings into
     * null, and strip any "data:image/...;base64," prefix so NHIF receives raw base64.
     */
    protected function prepareForValidation(): void
    {
        $imageData = $this->input('imageData');

        if (is_string($imageData)) {
            $imageData = trim((string) preg_replace('/^data:[^;]+;base64,/', '', trim($imageData)));
        }

        $this->merge([
            'biometricMethod' => is_string($this->input('biometricMethod'))
                ? strtoupper(trim($this->input('biometricMethod')))
                : $this->input('biometricMethod'),
            'fpCode' => filled($this->input('fpCode')) ? strtoupper(trim((string) $this->input('fpCode'))) : null,
            'imageData' => filled($imageData) ? $imageData : null,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $method = $this->input('biometricMethod');

        return [
            'biometricMethod' => ['required', 'string', Rule::in(self::BIOMETRIC_METHODS)],
            'fpCode' => [
                'nullable',
                'string',
                Rule::requiredIf($method === self::METHOD_FINGERPRINT),
                Rule::prohibitedIf($method !== self::METHOD_FINGERPRINT),
                Rule::in(self::FINGER_CODES),
            ],
            'imageData' => [
                'nullable',
                'string',
                Rule::requiredIf(in_array($method, [self::METHOD_FINGERPRINT, self::METHOD_FACIAL], true)),
                Rule::prohibitedIf($method === self::METHOD_NONE),
                'regex:/^[A-Za-z0-9+\/\r\n]+={0,2}$/',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'biometricMethod.required' => 'Select a biometric method (FINGERPRINT, NONE or FACIAL).',
            'biometricMethod.in' => 'Biometric method must be FINGERPRINT, NONE or FACIAL.',
            'fpCode.required' => 'Select the finger that was scanned.',
            'fpCode.in' => 'Finger code must be one of: '.implode(', ', self::FINGER_CODES).'.',
            'fpCode.prohibited' => 'A finger code is only allowed for the FINGERPRINT method.',
            'imageData.required' => 'Capture the biometric image before issuing.',
            'imageData.prohibited' => 'No biometric image should be sent when the method is NONE.',
            'imageData.regex' => 'The biometric image must be base64 encoded.',
        ];
    }
}
