<?php

namespace App\Http\Requests\Admin;

use App\Enums\ProductType;
use App\Models\Product;
use App\Support\Barcode;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\RichText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('edit-catalog');
    }

    protected function prepareForValidation(): void
    {
        $slug = trim((string) $this->input('slug'));

        $this->merge([
            'slug' => $slug !== '' ? strtolower($slug) : null,
            'brand' => trim((string) $this->input('brand')) ?: null,
            'seo_title' => trim((string) $this->input('seo_title')) ?: null,
            'seo_description' => trim((string) $this->input('seo_description')) ?: null,
            'attribute_ids' => array_values(array_map('intval', (array) $this->input('attribute_ids', []))),
        ]);
    }

    public function rules(): array
    {
        /** @var ?Product $product */
        $product = $this->route('product');

        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')->ignore($product?->id)],
            'brand' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:'.RichText::MAX_LENGTH],
            'type' => ['required', Rule::enum(ProductType::class)],
            'unit' => ['nullable', 'string', 'max:12'],
            'width_mm' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'depth_mm' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'height_mm' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'weight_g' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'assembly_required' => ['boolean'],
            'requires_delivery' => ['boolean'],
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:600'],
            'is_serialized' => ['boolean'],
            'publish_online' => ['boolean'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'is_active' => ['boolean'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['required', 'string', 'max:40'],
            'attribute_ids' => ['present', 'array', 'max:5'],
            'attribute_ids.*' => ['integer', 'exists:attributes,id'],
            'variants' => ['required', 'array', 'min:1', 'max:200'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.value_ids' => ['present', 'array'],
            'variants.*.value_ids.*' => ['integer', 'exists:attribute_values,id'],
            'variants.*.sku' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,63}$/'],
            'variants.*.barcode' => ['nullable', 'string', 'max:64', 'regex:'.Barcode::PATTERN],
            'variants.*.price' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'variants.*.min_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'variants.*.online_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'variants.*.reorder_level' => ['nullable', 'numeric', 'decimal:0,3', 'min:0'],
            'variants.*.reorder_qty' => ['nullable', 'numeric', 'decimal:0,3', 'min:0'],
            'variants.*.is_active' => ['boolean'],
            'variants.*.suppliers' => ['nullable', 'array'],
            'variants.*.suppliers.*.supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'variants.*.suppliers.*.supplier_sku' => ['nullable', 'string', 'max:64'],
            'variants.*.suppliers.*.last_cost' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'variants.*.suppliers.*.is_preferred' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'Use lowercase letters, numbers and dashes only (e.g. roma-sofa).',
            'variants.*.sku.required' => 'Enter a SKU.',
            'variants.*.sku.regex' => 'Letters, numbers and . _ / - only.',
            'variants.*.price.required' => 'Enter a price.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function productData(): array
    {
        $data = $this->safe()->except(['attribute_ids', 'variants']);
        $data['tags'] = array_values(array_unique(array_filter(array_map('trim', (array) ($data['tags'] ?? []))))) ?: null;

        return $data;
    }

    /**
     * @return array<int, int>
     */
    public function attributeIds(): array
    {
        return $this->validated('attribute_ids');
    }

    /**
     * Converts decimal prices to cents and decimal quantities to milli-units.
     *
     * @return array<int, array<string, mixed>>
     */
    public function variantsData(): array
    {
        $canViewCost = $this->user()->can('view-cost');

        return array_map(function (array $v) use ($canViewCost) {
            $row = [
                'id' => isset($v['id']) ? (int) $v['id'] : null,
                'value_ids' => array_map('intval', $v['value_ids'] ?? []),
                'sku' => strtoupper(trim((string) $v['sku'])),
                'barcode' => filled($v['barcode'] ?? null) ? trim((string) $v['barcode']) : null,
                'price' => Money::fromDecimal((string) $v['price'])->cents,
                'min_price' => filled($v['min_price'] ?? null) ? Money::fromDecimal((string) $v['min_price'])->cents : 0,
                'online_price' => filled($v['online_price'] ?? null) ? Money::fromDecimal((string) $v['online_price'])->cents : null,
                'reorder_level' => filled($v['reorder_level'] ?? null) ? Quantity::parse((string) $v['reorder_level']) : 0,
                'reorder_qty' => filled($v['reorder_qty'] ?? null) ? Quantity::parse((string) $v['reorder_qty']) : 0,
                'is_active' => (bool) ($v['is_active'] ?? true),
            ];

            if (array_key_exists('suppliers', $v) && $v['suppliers'] !== null) {
                $row['suppliers'] = array_map(fn (array $s) => [
                    'supplier_id' => (int) $s['supplier_id'],
                    'supplier_sku' => $s['supplier_sku'] ?? null,
                    'last_cost' => $canViewCost && filled($s['last_cost'] ?? null) ? Money::fromDecimal((string) $s['last_cost'])->cents : null,
                    'is_preferred' => (bool) ($s['is_preferred'] ?? false),
                ], $v['suppliers']);
            }

            return $row;
        }, $this->validated('variants'));
    }
}
