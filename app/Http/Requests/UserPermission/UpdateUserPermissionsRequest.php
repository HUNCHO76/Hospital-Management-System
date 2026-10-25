<?php

namespace App\Http\Requests\UserPermission;

use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserPermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $targetUser = $this->route('user');

        if (! $targetUser instanceof User) {
            return false;
        }

        // Must have permission management authorization
        $actor = $this->user();
        if (! $actor) {
            return false;
        }

        if ($actor->hasRole('super-admin')) {
            return true;
        }

        // Prevent self-escalation
        if ($actor->id === $targetUser->id) {
            return false;
        }

        // Prevent non-super-admins from modifying super-admins
        if ($targetUser->hasRole('super-admin')) {
            return false;
        }

        return $actor->can('permissions.assign')
            || $actor->can('users.update')
            || $actor->hasAnyRole(['hospital-admin', 'Manager', 'manager']);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'overrides' => ['required', 'array'],
            'overrides.*.permission_id' => [
                'required',
                'integer',
                Rule::exists('permissions', 'id')->where('guard_name', 'web'),
            ],
            'overrides.*.effect' => [
                'nullable',
                'string',
                Rule::in([UserPermissionOverride::EFFECT_ALLOW, UserPermissionOverride::EFFECT_DENY, 'inherit']),
            ],
            'overrides.*.reason' => ['nullable', 'string', 'max:500'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'overrides.required' => 'At least one permission override must be submitted.',
            'overrides.*.permission_id.exists' => 'The selected permission is invalid or does not belong to the web guard.',
            'overrides.*.effect.in' => 'The override effect must be either allow, deny, or inherit.',
        ];
    }
}
