<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Support\ImageUploader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProductImageController extends Controller
{
    public function store(Request $request, Product $product, ImageUploader $uploader): RedirectResponse
    {
        Gate::authorize('edit-catalog');

        $rules = ImageUploader::rules();
        // Required for each uploaded file in the array.
        $fileRules = array_values(array_filter($rules, fn ($r) => $r !== 'nullable'));

        $data = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:10'],
            'images.*' => ['required', ...$fileRules],
            'product_variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('product_id', $product->id)],
        ], ImageUploader::messages('images.*'));

        $sort = (int) $product->images()->max('sort_order');

        foreach ($request->file('images', []) as $file) {
            $paths = $uploader->store($file, 'products');
            $product->images()->create([
                ...$paths,
                'product_variant_id' => $data['product_variant_id'] ?? null,
                'alt' => $product->name,
                'sort_order' => ++$sort,
            ]);
        }

        return back()->with('success', 'Photo(s) uploaded.');
    }

    public function update(Request $request, Product $product, ProductImage $image): RedirectResponse
    {
        Gate::authorize('edit-catalog');
        abort_unless($image->product_id === $product->id, 404);

        $data = $request->validate([
            'alt' => ['nullable', 'string', 'max:255'],
            'product_variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('product_id', $product->id)],
        ]);

        $image->update([
            'alt' => trim((string) ($data['alt'] ?? '')) ?: null,
            'product_variant_id' => $data['product_variant_id'] ?? null,
        ]);

        return back()->with('success', 'Photo updated.');
    }

    public function reorder(Request $request, Product $product): RedirectResponse
    {
        Gate::authorize('edit-catalog');

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        DB::transaction(function () use ($product, $data) {
            foreach (array_values($data['ids']) as $index => $id) {
                $product->images()->whereKey((int) $id)->update(['sort_order' => $index]);
            }
        });

        return back();
    }

    public function destroy(Product $product, ProductImage $image, ImageUploader $uploader): RedirectResponse
    {
        Gate::authorize('edit-catalog');
        abort_unless($image->product_id === $product->id, 404);

        $uploader->delete($image->image_path, $image->thumbnail_path);
        $image->delete();

        return back()->with('success', 'Photo removed.');
    }
}
