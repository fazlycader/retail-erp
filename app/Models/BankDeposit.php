<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Cash from the safe (or straight from a shift) and/or cheques taken to the bank on one slip. */
class BankDeposit extends Model
{
    use BelongsToLocation;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'deposited_on' => 'date',
            'business_date' => 'date',
            'cash_amount' => 'integer',
            'cheque_amount' => 'integer',
            'cheque_count' => 'integer',
        ];
    }

    public function total(): int
    {
        return $this->cash_amount + $this->cheque_amount;
    }

    public function cheques(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
