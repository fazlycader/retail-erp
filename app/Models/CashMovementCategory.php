<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Why cash went in or out (shared by every location). applies_to: any | pay_in | pay_out | cash_drop. */
#[Fillable(['name', 'applies_to', 'is_active', 'sort_order'])]
class CashMovementCategory extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
