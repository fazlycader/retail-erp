<?php

namespace App\Services\Woo;

use App\Models\Category;
use App\Models\WooLink;

/**
 * Local category (with its parents) → WooCommerce product category id. Links are reused; a
 * missing one is matched by slug in WooCommerce first and only created when it doesn't exist there.
 */
class CategorySync
{
    public function __construct(private WooClient $woo) {}

    public function wooId(Category $category): int
    {
        if ($link = WooLink::for(WooLink::CATEGORY, $category->id)) {
            return $link->woo_id;
        }

        $parentId = $category->parent_id ? $this->wooId(Category::query()->findOrFail($category->parent_id)) : 0;
        $existing = collect($this->woo->get('products/categories', ['slug' => $category->slug]))->first();
        $woo = $existing ?? $this->woo->post('products/categories', ['name' => $category->name, 'slug' => $category->slug, 'parent' => $parentId]);

        return WooLink::remember(WooLink::CATEGORY, $category->id, (int) $woo['id'])->woo_id;
    }
}
