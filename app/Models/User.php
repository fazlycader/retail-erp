<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'role', 'home_location_id', 'is_active', 'pin', 'password'])]
#[Hidden(['password', 'pin', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** After this many wrong PINs within the window the PIN is locked for PIN_LOCK_MINUTES. */
    public const PIN_MAX_ATTEMPTS = 10;

    public const PIN_WINDOW_MINUTES = 15;

    public const PIN_LOCK_MINUTES = 15;

    protected $attributes = [
        'is_active' => true,
    ];

    /** @var array<int, int>|null */
    private ?array $locationIdCache = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'pin' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'role' => Role::class,
            'is_active' => 'boolean',
            'pin_failed_attempts' => 'integer',
            'pin_last_failed_at' => 'datetime',
            'pin_locked_until' => 'datetime',
        ];
    }

    public function homeLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'home_location_id');
    }

    /** Home + extra locations (the home location is always in the pivot). */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class);
    }

    /**
     * Ids of the user's home + extra locations (any status).
     *
     * @return array<int, int>
     */
    public function locationIds(): array
    {
        return $this->locationIdCache ??= array_values(array_unique(array_filter([
            $this->home_location_id,
            ...$this->locations()->pluck('locations.id')->map(fn ($id) => (int) $id)->all(),
        ])));
    }

    /**
     * Give the user a home location plus extra ones. The home is always kept in the pivot.
     * $keep are linked ids the caller may not change (a manager editing someone who also works
     * at a location the manager doesn't run).
     *
     * @param  array<int, int>  $extra
     * @param  array<int, int>  $keep
     */
    public function assignLocations(?int $homeId, array $extra = [], array $keep = []): void
    {
        $this->forceFill(['home_location_id' => $homeId])->save();
        $this->locations()->sync(array_values(array_unique(array_filter([$homeId, ...$extra, ...$keep]))));
        $this->locationIdCache = null;
    }

    public function worksAt(Location|int $location): bool
    {
        $id = $location instanceof Location ? $location->id : $location;

        return $this->role->seesAllLocations() || in_array($id, $this->locationIds(), true);
    }

    public function hasRole(Role|string ...$roles): bool
    {
        foreach ($roles as $role) {
            if ($this->role === ($role instanceof Role ? $role : Role::from($role))) {
                return true;
            }
        }

        return false;
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function hasPin(): bool
    {
        return $this->pin !== null;
    }

    public function checkPin(string $pin): bool
    {
        return $this->hasPin() && Hash::check($pin, $this->pin);
    }

    public function pinLocked(): bool
    {
        return $this->pin_locked_until !== null && $this->pin_locked_until->isFuture();
    }

    /**
     * Check a PIN with lockout. Returns true on success; counts failures and locks the PIN
     * after PIN_MAX_ATTEMPTS wrong tries in PIN_WINDOW_MINUTES (audited). A locked PIN always
     * fails until it expires or a manager unlocks it.
     */
    public function attemptPin(string $pin, ?string $context = null): bool
    {
        if ($this->pinLocked()) {
            return false;
        }

        if ($this->checkPin($pin)) {
            if ($this->pin_failed_attempts > 0 || $this->pin_locked_until) {
                $this->forceFill(['pin_failed_attempts' => 0, 'pin_last_failed_at' => null, 'pin_locked_until' => null])->saveQuietly();
            }

            return true;
        }

        $recent = $this->pin_last_failed_at && $this->pin_last_failed_at->gt(now()->subMinutes(self::PIN_WINDOW_MINUTES));
        $attempts = ($recent ? $this->pin_failed_attempts : 0) + 1;
        $locked = $attempts >= self::PIN_MAX_ATTEMPTS;

        $this->forceFill([
            'pin_failed_attempts' => $locked ? 0 : $attempts,
            'pin_last_failed_at' => now(),
            'pin_locked_until' => $locked ? now()->addMinutes(self::PIN_LOCK_MINUTES) : $this->pin_locked_until,
        ])->saveQuietly();

        if ($locked) {
            AuditLog::record('user.pin_locked', $this, "{$this->name}'s PIN was locked for ".self::PIN_LOCK_MINUTES.' minutes after '.self::PIN_MAX_ATTEMPTS.' wrong tries'.($context ? " ({$context})" : ''), null, null, null);
        }

        return false;
    }

    public function unlockPin(): void
    {
        $this->forceFill(['pin_failed_attempts' => 0, 'pin_last_failed_at' => null, 'pin_locked_until' => null])->saveQuietly();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Staff linked to a location (home or extra). */
    public function scopeAtLocation(Builder $query, int $locationId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('home_location_id', $locationId)
            ->orWhereHas('locations', fn (Builder $l) => $l->whereKey($locationId)));
    }

    public function refresh()
    {
        $this->locationIdCache = null;

        return parent::refresh();
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }
}
