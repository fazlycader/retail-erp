<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallmentGuarantor extends Model
{
    protected $guarded = [];

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(InstallmentAgreement::class, 'installment_agreement_id');
    }
}
