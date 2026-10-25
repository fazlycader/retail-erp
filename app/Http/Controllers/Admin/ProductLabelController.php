<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Support\Barcode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductLabelController extends Controller
{
    public function index(Request $request): Response
    {
        $productIds = array_filter(array_map('intval', (array) $request->input('product_ids', [])));
        $variantIds = array_filter(array_map('intval', (array) $request->input('variant_ids', [])));

        $query = ProductVariant::query()
            ->with('product:id,name,brand,width_mm,depth_mm,height_mm')
            ->whereHas('product', fn ($q) => $q->whereNull('deleted_at'))
            ->orderBy('product_id')
            ->orderBy('sort_order');

        if ($variantIds !== []) {
            $variants = $query->whereIn('id', $variantIds)->limit(200)->get();
        } elseif ($productIds !== []) {
            $variants = $query->whereIn('product_id', $productIds)->where('is_active', true)->limit(200)->get();
        } else {
            $variants = $query->where('is_active', true)->limit(12)->get();
        }

        return Inertia::render('Admin/Products/Labels', [
            'initialVariants' => $variants->map(fn (ProductVariant $v) => self::formatVariant($v))->values()->all(),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->input('q'));

        if ($term === '') {
            return response()->json(['variants' => []]);
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $variants = ProductVariant::query()
            ->with('product:id,name,brand,width_mm,depth_mm,height_mm')
            ->whereHas('product', fn ($q) => $q->whereNull('deleted_at'))
            ->where(fn ($q) => $q
                ->where('sku', 'like', $like)
                ->orWhere('barcode', $term)
                ->orWhere('name', 'like', $like)
                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', $like)))
            ->orderBy('product_id')
            ->orderBy('sort_order')
            ->limit(25)
            ->get();

        return response()->json([
            'variants' => $variants->map(fn (ProductVariant $v) => self::formatVariant($v))->values()->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function formatVariant(ProductVariant $v): array
    {
        $p = $v->product;
        $dims = array_filter([$p?->width_mm, $p?->depth_mm, $p?->height_mm]);

        return [
            'id' => $v->id,
            'product_id' => $v->product_id,
            'product_name' => $p?->name ?? '',
            'brand' => $p?->brand,
            'variant_name' => $v->name,
            'sku' => $v->sku,
            'barcode' => $v->barcode,
            'price' => $v->price,
            'dimensions' => count($dims) === 3 ? "{$p->width_mm}×{$p->depth_mm}×{$p->height_mm} mm" : null,
            'barcode_svg' => Barcode::svg($v->barcode, 180, 36),
            'copies' => 1,
        ];
    }
}
