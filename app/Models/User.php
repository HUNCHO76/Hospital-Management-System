<?php

namespace App\Models;

use App\Models\Concerns\HasPermissionOverrides;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;
    use HasRoles, HasPermissionOverrides {
        HasPermissionOverrides::hasPermissionTo insteadof HasRoles;
        HasPermissionOverrides::checkPermissionTo insteadof HasRoles;
        HasPermissionOverrides::getAllPermissions insteadof HasRoles;
        HasRoles::hasPermissionTo as spatieHasPermissionTo;
        HasRoles::checkPermissionTo as spatieCheckPermissionTo;
        HasRoles::getAllPermissions as spatieGetAllPermissions;
    }

    /**
     * Account status values recognized by the authentication system.
     */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_SUSPENDED = 'suspended';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'UserName',
        'email',
        'phone',
        'password',
        'status',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The staff profile associated with this user account.
     */
    public function staff(): HasOne
    {
        return $this->hasOne(Staff::class);
    }

    /**
     * Determine whether the user's account is active and permitted to authenticate.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * The audit log entries recorded for actions performed by this user.
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
