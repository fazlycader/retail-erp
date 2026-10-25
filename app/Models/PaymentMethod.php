<?php

namespace App\Models;

use App\Enums\PaymentMethodCode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'requires_reference', 'opens_cash_drawer', 'is_active', 'sort'])]
class PaymentMethod extends Model
{
    protected function casts(): array
    {
        return [
            'code' => PaymentMethodCode::class,
            'requires_reference' => 'boolean',
            'opens_cash_drawer' => 'boolean',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort')->orderBy('id');
    }

    public static function byCode(PaymentMethodCode $code): self
    {
        return static::query()->where('code', $code->value)->firstOrFail();
    }

    /**
     * @return array{id: int, code: string, name: string, requires_reference: bool, opens_cash_drawer: bool}
     */
    public function toOption(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code->value,
            'name' => $this->name,
            'requires_reference' => $this->requires_reference,
            'opens_cash_drawer' => $this->opens_cash_drawer,
        ];
    }
}
