<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstallmentPlan extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'terms' => 'array',
            'minimum_down_payment_bp' => 'integer',
            'flat_bp' => 'integer',
            'document_fee_cents' => 'integer',
            'late_fee_cents' => 'integer',
            'late_fee_bp' => 'integer',
            'grace_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function agreements(): HasMany
    {
        return $this->hasMany(InstallmentAgreement::class);
    }
}
