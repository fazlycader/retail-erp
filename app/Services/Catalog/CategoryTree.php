<?php

namespace App\Services\Catalog;

use App\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The category tree, loaded once (a few hundred rows at most). Depth is never stored: it is
 * worked out here, so moves can't leave a stale value behind. Max Category::MAX_DEPTH levels.
 */
class CategoryTree
{
    /** @var Collection<int, Category> */
    private Collection $all;

    public function __construct()
    {
        $this->all = Category::query()->orderBy('sort_order')->orderBy('id')->get()->keyBy('id');
    }

    /** 1 = top level. */
    public function depth(Category|int $category): int
    {
        $id = $category instanceof Category ? $category->id : $category;
        $depth = 0;

        for ($node = $this->all->get($id); $node && $depth <= Category::MAX_DEPTH + 1; $node = $this->all->get($node->parent_id)) {
            $depth++;
        }

        return $depth;
    }

    /** Levels below and including the category: a leaf is 1. */
    public function height(int $id): int
    {
        $children = $this->all->where('parent_id', $id);

        return 1 + ($children->isEmpty() ? 0 : $children->map(fn (Category $c) => $this->height($c->id))->max());
    }

    /**
     * @return array<int, int> the category and everything under it
     */
    public function descendantIds(int $id): array
    {
        $ids = [$id];

        foreach ($this->all->where('parent_id', $id) as $child) {
            array_push($ids, ...$this->descendantIds($child->id));
        }

        return $ids;
    }

    /** Why $id can't go under $parentId (null = it can). */
    public function moveProblem(?int $id, ?int $parentId): ?string
    {
        if ($parentId === null) {
            return $id && $this->height($id) > Category::MAX_DEPTH ? 'Categories can only be '.Category::MAX_DEPTH.' levels deep.' : null;
        }

        if (! $this->all->has($parentId)) {
            return 'That parent category does not exist.';
        }

        if ($id && in_array($parentId, $this->descendantIds($id), true)) {
            return "A category can't go inside itself.";
        }

        $height = $id ? $this->height($id) : 1;

        return $this->depth($parentId) + $height > Category::MAX_DEPTH
            ? 'Categories can only be '.Category::MAX_DEPTH.' levels deep.'
            : null;
    }

    /**
     * Save a dragged tree: [{id, parent_id, sort_order}, …] for every category that moved.
     * Validated as a whole against the final shape, then written in one transaction.
     *
     * @param  array<int, array{id: int, parent_id: ?int, sort_order: int}>  $nodes
     */
    public function rearrange(array $nodes): void
    {
        $next = $this->all->map(fn (Category $c) => clone $c);

        foreach ($nodes as $node) {
            $category = $next->get($node['id']) ?? throw new InvalidArgumentException('Unknown category.');
            $category->parent_id = $node['parent_id'];
            $category->sort_order = $node['sort_order'];
        }

        $shape = new self;
        $shape->all = $next;

        foreach ($next as $category) {
            if ($shape->depth($category) > Category::MAX_DEPTH) {
                throw new InvalidArgumentException('Categories can only be '.Category::MAX_DEPTH.' levels deep.');
            }
        }

        DB::transaction(function () use ($nodes) {
            foreach ($nodes as $node) {
                Category::query()->whereKey($node['id'])->update(['parent_id' => $node['parent_id'], 'sort_order' => $node['sort_order']]);
            }
        });

        $this->all = $next;
    }

    /** "Living room › Sofas › Recliners". */
    public function path(Category|int $category, string $separator = ' › '): string
    {
        $id = $category instanceof Category ? $category->id : $category;
        $names = [];

        for ($node = $this->all->get($id); $node && count($names) <= Category::MAX_DEPTH; $node = $this->all->get($node->parent_id)) {
            array_unshift($names, $node->name);
        }

        return implode($separator, $names);
    }

    /**
     * Find a category by its full path ("Living room > Sofas"), case-insensitive; '>' or '›' separate
     * levels. Exact matches only: a name that exists in two places must be given with its path.
     */
    public function findByPath(string $path): ?Category
    {
        $wanted = $this->normalisePath($path);

        return $this->all->first(fn (Category $c) => $this->normalisePath($this->path($c, '>')) === $wanted);
    }

    /**
     * Nested for the tree screen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function nested(?int $parentId = null): array
    {
        return $this->all->where('parent_id', $parentId)->values()->map(fn (Category $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'slug' => $c->slug,
            'is_active' => $c->is_active,
            'is_material' => $c->is_material,
            'thumbnail_url' => $c->thumbnailUrl(),
            'default_warranty_months' => $c->default_warranty_months,
            'commission_group' => $c->commission_group,
            'parent_id' => $c->parent_id,
            'depth' => $this->depth($c),
            'children' => $this->nested($c->id),
        ])->all();
    }

    /**
     * Flat, in tree order, with full paths, for selects.
     *
     * @return array<int, array{id: int, name: string, path: string, depth: int, is_active: bool}>
     */
    public function options(bool $activeOnly = false): array
    {
        $out = [];
        $walk = function (?int $parentId) use (&$walk, &$out, $activeOnly) {
            foreach ($this->all->where('parent_id', $parentId) as $c) {
                if ($activeOnly && ! $c->is_active) {
                    continue;
                }

                $out[] = ['id' => $c->id, 'name' => $c->name, 'path' => $this->path($c), 'depth' => $this->depth($c), 'is_active' => $c->is_active];
                $walk($c->id);
            }
        };
        $walk(null);

        return $out;
    }

    private function normalisePath(string $path): string
    {
        $parts = preg_split('/\s*(?:>|›)\s*/u', mb_strtolower(trim($path)));

        return implode('>', array_map(fn ($p) => preg_replace('/\s+/', ' ', trim($p)), $parts));
    }
}
