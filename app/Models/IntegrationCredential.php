<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One external-service credential (formerly an .env variable), e.g. WOO_CONSUMER_KEY. Read it through App\Facades\Integrations, never directly: that layer
 * applies fallbacks and caching. `value` and `previous_value` are encrypted at rest and hidden from arrays/JSON.
 */
class IntegrationCredential extends Model
{
    protected $fillable = ['provider', 'key_name', 'value', 'previous_value', 'previous_changed_at', 'is_secret', 'description', 'guide', 'updated_by'];

    protected $hidden = ['value', 'previous_value'];

    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
            'previous_value' => 'encrypted',
            'previous_changed_at' => 'datetime',
            'is_secret' => 'boolean',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isSet(): bool
    {
        return filled($this->value);
    }

    /**
     * What the screen shows. Secrets: "••••" + the last 4 characters (the passport convention, but with a fixed number of dots so the
     * length isn't revealed), and only the dots for anything under 12 characters, where 4 would be too much of it.
     */
    public function masked(): ?string
    {
        return self::mask($this->value, $this->is_secret);
    }

    public function maskedPrevious(): ?string
    {
        return self::mask($this->previous_value, $this->is_secret);
    }

    public static function mask(?string $value, bool $secret): ?string
    {
        if (blank($value)) {
            return null;
        }

        if (! $secret) {
            return $value;
        }

        return mb_strlen($value) >= 12 ? '••••'.mb_substr($value, -4) : '••••';
    }
}
