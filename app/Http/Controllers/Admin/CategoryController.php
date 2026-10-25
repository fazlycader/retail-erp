<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Services\Catalog\CategoryTree;
use App\Support\ImageUploader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class CategoryController extends Controller
{
    public function index(Request $request, CategoryTree $tree): Response
    {
        $counts = Category::query()->withCount('products')->pluck('products_count', 'id');

        $addCounts = function (array $nodes) use (&$addCounts, $counts): array {
            return array_map(fn (array $node) => [
                ...$node,
                'products_count' => (int) ($counts[$node['id']] ?? 0),
                'children' => $addCounts($node['children']),
            ], $nodes);
        };

        return Inertia::render('Admin/Categories/Index', [
            'tree' => $addCounts($tree->nested()),
            'options' => $tree->options(),
            'maxDepth' => Category::MAX_DEPTH,
            'canEdit' => $request->user()->can('edit-catalog'),
        ]);
    }

    public function store(Request $request, CategoryTree $tree, ImageUploader $uploader): RedirectResponse
    {
        Gate::authorize('edit-catalog');

        $data = $this->validateCategory($request);
        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;

        if ($problem = $tree->moveProblem(null, $parentId)) {
            throw ValidationException::withMessages(['parent_id' => $problem]);
        }

        $category = new Category([
            'parent_id' => $parentId,
            'name' => $data['name'],
            'slug' => $data['slug'] ?: $this->uniqueSlug($data['name']),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'is_material' => (bool) ($data['is_material'] ?? false),
            'default_warranty_months' => $data['default_warranty_months'] ?? null,
            'commission_group' => $data['commission_group'] ?? null,
            'sort_order' => (int) Category::query()->where('parent_id', $parentId)->max('sort_order') + 1,
        ]);

        if ($request->hasFile('image')) {
            $paths = $uploader->store($request->file('image'), 'categories');
            $category->image_path = $paths['image_path'];
            $category->thumbnail_path = $paths['thumbnail_path'];
        }

        $category->save();

        AuditLog::record('category.created', $category, "Added category {$category->name}");

        return back()->with('success', "{$category->name} was added.");
    }

    public function update(Request $request, Category $category, CategoryTree $tree, ImageUploader $uploader): RedirectResponse
    {
        Gate::authorize('edit-catalog');

        $data = $this->validateCategory($request, $category);
        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;

        if ($problem = $tree->moveProblem($category->id, $parentId)) {
            throw ValidationException::withMessages(['parent_id' => $problem]);
        }

        $category->fill([
            'parent_id' => $parentId,
            'name' => $data['name'],
            'slug' => $data['slug'] ?: $this->uniqueSlug($data['name'], $category->id),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'is_material' => (bool) ($data['is_material'] ?? false),
            'default_warranty_months' => $data['default_warranty_months'] ?? null,
            'commission_group' => $data['commission_group'] ?? null,
        ]);

        if ($request->hasFile('image') || $request->boolean('remove_image')) {
            $uploader->delete($category->image_path, $category->thumbnail_path);

            if ($request->hasFile('image')) {
                $paths = $uploader->store($request->file('image'), 'categories');
                $category->image_path = $paths['image_path'];
                $category->thumbnail_path = $paths['thumbnail_path'];
            } else {
                $category->image_path = null;
                $category->thumbnail_path = null;
            }
        }

        $category->save();

        AuditLog::record('category.updated', $category, "Updated category {$category->name}");

        return back()->with('success', "{$category->name} was saved.");
    }

    public function rearrange(Request $request, CategoryTree $tree): RedirectResponse
    {
        Gate::authorize('edit-catalog');

        $data = $request->validate([
            'nodes' => ['required', 'array', 'min:1'],
            'nodes.*.id' => ['required', 'integer', 'exists:categories,id'],
            'nodes.*.parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'nodes.*.sort_order' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $tree->rearrange(array_map(fn (array $n) => [
                'id' => (int) $n['id'],
                'parent_id' => isset($n['parent_id']) && $n['parent_id'] !== null ? (int) $n['parent_id'] : null,
                'sort_order' => (int) $n['sort_order'],
            ], $data['nodes']));
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Category order saved.');
    }

    public function destroy(Category $category, ImageUploader $uploader): RedirectResponse
    {
        Gate::authorize('edit-catalog');

        if ($category->children()->exists()) {
            return back()->with('error', 'Move or delete its subcategories first.');
        }

        if ($category->products()->withTrashed()->exists()) {
            return back()->with('error', 'Move its products to another category before deleting it.');
        }

        $name = $category->name;
        $uploader->delete($category->image_path, $category->thumbnail_path);
        $category->delete();

        AuditLog::record('category.deleted', null, "Deleted category {$name}");

        return back()->with('success', "{$name} was deleted.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCategory(Request $request, ?Category $category = null): array
    {
        $slug = trim((string) $request->input('slug'));
        $request->merge([
            'slug' => $slug !== '' ? strtolower($slug) : null,
            'commission_group' => trim((string) $request->input('commission_group')) ?: null,
        ]);

        return $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('categories', 'slug')->ignore($category?->id)],
            'is_active' => ['boolean'],
            'is_material' => ['boolean'],
            'default_warranty_months' => ['nullable', 'integer', 'min:0', 'max:600'],
            'commission_group' => ['nullable', 'string', 'max:40'],
            'image' => ImageUploader::rules(),
            'remove_image' => ['boolean'],
        ], ImageUploader::messages('image'));
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;

        for ($i = 2; Category::query()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
