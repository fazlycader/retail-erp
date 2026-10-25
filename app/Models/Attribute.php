<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Material, Colour, Fabric, Size, Finish…: shared by every product. */
#[Fillable(['name', 'sort_order'])]
class Attribute extends Model
{
    use HasFactory;

    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class)->orderBy('sort_order')->orderBy('id');
    }
}
