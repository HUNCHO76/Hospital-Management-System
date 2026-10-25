<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StaffAccountActivation extends Model
{
    protected $fillable = [
        'user_id',
        'staff_id',
        'token_hash',
        'email',
        'expires_at',
        'activated_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Generate a new single-use activation record for a user.
     * Returns an array with ['activation' => StaffAccountActivation, 'plainToken' => string].
     *
     * @return array{activation: self, plainToken: string}
     */
    public static function createForUser(User $user, ?Staff $staff = null, ?string $email = null, ?User $actor = null, int $expiryDays = 7): array
    {
        $plainToken = Str::random(64);
        $tokenHash = hash('sha256', $plainToken);

        // Invalidate any existing pending activations for this user
        static::where('user_id', $user->id)
            ->whereNull('activated_at')
            ->delete();

        $activation = static::create([
            'user_id' => $user->id,
            'staff_id' => $staff?->id ?? $user->staff?->id,
            'token_hash' => $tokenHash,
            'email' => $email ?? $user->email,
            'expires_at' => now()->addDays($expiryDays),
            'created_by' => $actor?->id,
        ]);

        return [
            'activation' => $activation,
            'plainToken' => $plainToken,
        ];
    }

    /**
     * Find a valid, unexpired activation by its plaintext token.
     */
    public static function findValidByPlainToken(string $plainToken): ?self
    {
        $hash = hash('sha256', $plainToken);

        return static::query()
            ->where('token_hash', $hash)
            ->whereNull('activated_at')
            ->where('expires_at', '>', now())
            ->with(['user', 'staff'])
            ->first();
    }
}
