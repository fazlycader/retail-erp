<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\AuditLog;
use App\Support\ImageUploader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AttributeController extends Controller
{
    public function index(Request $request): Response
    {
        $attributes = Attribute::query()
            ->with(['values' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Attribute $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'sort_order' => $a->sort_order,
                'values' => $a->values->map(fn (AttributeValue $v) => [
                    ...$v->toOption(),
                    'sort_order' => $v->sort_order,
                ])->values()->all(),
            ])->values()->all();

        return Inertia::render('Admin/Attributes/Index', [
            'attributes' => $attributes,
            'canEdit' => $request->user()->can('edit-catalog'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('edit-catalog');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', 'unique:attributes,name'],
        ]);

        $attribute = Attribute::query()->create([
            'name' => trim($data['name']),
            'sort_order' => (int) Attribute::query()->max('sort_order') + 1,
        ]);

        AuditLog::record('attribute.created', $attribute, "Added option {$attribute->name}");

        return back()->with('success', "{$attribute->name} was added.");
    }

    public function update(Request $request, Attribute $attribute): RedirectResponse
    {
        Gate::authorize('edit-catalog');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('attributes', 'name')->ignore($attribute->id)],
        ]);

        $attribute->update(['name' => trim($data['name'])]);
        AuditLog::record('attribute.updated', $attribute, "Updated option {$attribute->name}");

        return back()->with('success', "{$attribute->name} was saved.");
    }

    public function reorder(Request $request): RedirectResponse
    {
        Gate::authorize('edit-catalog');

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        DB::transaction(function () use ($data) {
            foreach (array_values($data['ids']) as $index => $id) {
                Attribute::query()->whereKey((int) $id)->update(['sort_order' => $index]);
            }
        });

        return back();
    }

    public function destroy(Attribute $attribute): RedirectResponse
    {
        Gate::authorize('edit-catalog');

        if (DB::table('attribute_product')->where('attribute_id', $attribute->id)->exists()) {
            return back()->with('error', "{$attribute->name} is used on products and cannot be deleted.");
        }

        $name = $attribute->name;
        $attribute->delete();
        AuditLog::record('attribute.deleted', null, "Deleted option {$name}");

        return back()->with('success', "{$name} was deleted.");
    }

    public function storeValue(Request $request, Attribute $attribute, ImageUploader $uploader): RedirectResponse
    {
        Gate::authorize('edit-catalog');

        $data = $this->validateValue($request, $attribute);

        $value = new AttributeValue([
            'attribute_id' => $attribute->id,
            'value' => trim($data['value']),
            'code' => $data['code'] ?? '',
            'swatch_color' => $data['swatch_color'] ?: null,
            'sort_order' => (int) $attribute->values()->max('sort_order') + 1,
        ]);

        if ($request->hasFile('swatch_image')) {
            $paths = $uploader->store($request->file('swatch_image'), 'swatches');
            $value->swatch_image_path = $paths['thumbnail_path'];
            $uploader->delete($paths['image_path']);
        }

        $value->save();

        return back()->with('success', "Added {$value->value} to {$attribute->name}.");
    }

    public function updateValue(Request $request, Attribute $attribute, AttributeValue $value, ImageUploader $uploader): RedirectResponse
    {
        Gate::authorize('edit-catalog');
        abort_unless($value->attribute_id === $attribute->id, 404);

        $data = $this->validateValue($request, $attribute, $value);

        $value->fill([
            'value' => trim($data['value']),
            'code' => $data['code'] ?? '',
            'swatch_color' => $data['swatch_color'] ?: null,
        ]);

        if ($request->hasFile('swatch_image') || $request->boolean('remove_swatch_image')) {
            $uploader->delete($value->swatch_image_path);

            if ($request->hasFile('swatch_image')) {
                $paths = $uploader->store($request->file('swatch_image'), 'swatches');
                $value->swatch_image_path = $paths['thumbnail_path'];
                $uploader->delete($paths['image_path']);
            } else {
                $value->swatch_image_path = null;
            }
        }

        $value->save();

        return back()->with('success', "{$value->value} was saved.");
    }

    public function reorderValues(Request $request, Attribute $attribute): RedirectResponse
    {
        Gate::authorize('edit-catalog');

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        DB::transaction(function () use ($attribute, $data) {
            foreach (array_values($data['ids']) as $index => $id) {
                $attribute->values()->whereKey((int) $id)->update(['sort_order' => $index]);
            }
        });

        return back();
    }

    public function destroyValue(Attribute $attribute, AttributeValue $value, ImageUploader $uploader): RedirectResponse
    {
        Gate::authorize('edit-catalog');
        abort_unless($value->attribute_id === $attribute->id, 404);

        if (DB::table('variant_attribute_value')->where('attribute_value_id', $value->id)->exists()) {
            return back()->with('error', "{$value->value} is used on product variants and cannot be deleted.");
        }

        $uploader->delete($value->swatch_image_path);
        $value->delete();

        return back()->with('success', 'Option value deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateValue(Request $request, Attribute $attribute, ?AttributeValue $value = null): array
    {
        $request->merge([
            'swatch_color' => trim((string) $request->input('swatch_color')) ?: null,
        ]);

        return $request->validate([
            'value' => [
                'required',
                'string',
                'max:80',
                Rule::unique('attribute_values', 'value')
                    ->where('attribute_id', $attribute->id)
                    ->ignore($value?->id),
            ],
            'code' => ['nullable', 'string', 'max:12'],
            'swatch_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'swatch_image' => ImageUploader::rules(),
            'remove_swatch_image' => ['boolean'],
        ], ImageUploader::messages('swatch_image'));
    }
}
