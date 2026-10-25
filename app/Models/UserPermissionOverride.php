<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Permission;

/**
 * An explicit user-specific permission override (allow or deny).
 *
 * Rules:
 * - 'allow': Explicitly grants a permission to this specific user, even if not in their roles.
 * - 'deny': Explicitly denies a permission for this specific user, overriding role and direct permissions.
 */
class UserPermissionOverride extends Model
{
    use HasFactory;

    public const EFFECT_ALLOW = 'allow';

    public const EFFECT_DENY = 'deny';

    public const EFFECTS = [
        self::EFFECT_ALLOW,
        self::EFFECT_DENY,
    ];

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'permission_id',
        'effect',
        'reason',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'permission_id' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isAllow(): bool
    {
        return $this->effect === self::EFFECT_ALLOW;
    }

    public function isDeny(): bool
    {
        return $this->effect === self::EFFECT_DENY;
    }
}
