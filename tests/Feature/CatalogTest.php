<?php

use App\Enums\ProductType;
use App\Enums\Role;
use App\Exceptions\ApprovalRefused;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\CompanySetting;
use App\Models\Location;
use App\Models\PriceChangeLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Catalog\PriceFloor;
use App\Services\Catalog\ProductService;
use App\Services\Catalog\VariantGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('variant generator builds every combination in order and keeps existing variants on regenerate', function () {
    $colour = Attribute::create(['name' => 'Colour', 'sort_order' => 0]);
    $grey = AttributeValue::create(['attribute_id' => $colour->id, 'value' => 'Grey', 'code' => 'GRY']);
    $green = AttributeValue::create(['attribute_id' => $colour->id, 'value' => 'Green', 'code' => 'GRY']); // same code to test deduplication

    $size = Attribute::create(['name' => 'Size', 'sort_order' => 1]);
    $two = AttributeValue::create(['attribute_id' => $size->id, 'value' => '2 Seater', 'code' => '2S']);
    $three = AttributeValue::create(['attribute_id' => $size->id, 'value' => '3 Seater', 'code' => '3S']);

    $category = Category::factory()->create();
    $product = app(ProductService::class)->save(
        new Product,
        ['category_id' => $category->id, 'name' => 'Roma Sofa', 'type' => ProductType::Stock],
        [$colour->id, $size->id],
        [
            ['value_ids' => [$grey->id, $two->id], 'sku' => 'CUSTOM-GREY-2S', 'price' => 100000, 'min_price' => 90000, 'online_price' => null, 'reorder_level' => 0, 'reorder_qty' => 0, 'is_active' => true],
        ],
    );

    $existing = $product->variants()->get();
    $rows = app(VariantGenerator::class)->generate(
        [$colour->id => [$grey->id, $green->id], $size->id => [$two->id, $three->id]],
        'ROMA',
        $existing,
    );

    expect($rows)->toHaveCount(4)
        ->and($rows[0]['name'])->toBe('Grey / 2 Seater')
        ->and($rows[0]['sku'])->toBe('CUSTOM-GREY-2S')
        ->and($rows[0]['existing_id'])->toBe($existing->first()->id)
        ->and($rows[1]['sku'])->toBe('ROMA-GRY-3S')
        ->and($rows[2]['sku'])->toBe('ROMA-GRY-2S')
        ->and($rows[3]['sku'])->toBe('ROMA-GRY-3S-2');
});

test('a product without options has one default variant and generates a Code 128 barcode when blank', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create(['default_warranty_months' => 24]);

    $this->actingAs($admin)
        ->post(route('products.store'), [
            'category_id' => $category->id,
            'name' => 'Teak Coffee Table',
            'description' => '<p>Solid teak</p><script>alert(1)</script>',
            'type' => 'stock',
            'attribute_ids' => [],
            'variants' => [
                [
                    'value_ids' => [],
                    'sku' => 'tbl-teak-01',
                    'barcode' => '',
                    'price' => '45000.00',
                    'min_price' => '40000.00',
                    'online_price' => '',
                    'reorder_level' => '2',
                    'reorder_qty' => '5',
                    'is_active' => true,
                ],
            ],
        ])
        ->assertRedirect();

    $product = Product::firstOrFail();
    $variant = $product->variants()->firstOrFail();

    expect($product->description)->toBe('<p>Solid teak</p>')
        ->and($product->effectiveWarrantyMonths())->toBe(24)
        ->and($variant->isDefault())->toBeTrue()
        ->and($variant->sku)->toBe('TBL-TEAK-01')
        ->and($variant->barcode)->toStartWith('20')
        ->and(strlen($variant->barcode))->toBe(12)
        ->and($variant->price)->toBe(4500000)
        ->and($variant->min_price)->toBe(4000000)
        ->and($variant->effectiveOnlinePrice())->toBe(4500000)
        ->and($variant->reorder_level)->toBe(2000);
});

test('SKUs and barcodes must be unique across products including soft-deleted variants', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();
    $service = app(ProductService::class);

    $first = $service->save(
        new Product,
        ['category_id' => $category->id, 'name' => 'First Chair', 'type' => ProductType::Stock],
        [],
        [['value_ids' => [], 'sku' => 'CHR-01', 'barcode' => '8901234567890', 'price' => 10000, 'min_price' => 8000, 'online_price' => null, 'reorder_level' => 0, 'reorder_qty' => 0, 'is_active' => true]],
    );

    // Soft-delete the first product's variant by replacing its SKU, or deleting the product.
    $first->variants()->first()->delete();

    $this->actingAs($admin)
        ->post(route('products.store'), [
            'category_id' => $category->id,
            'name' => 'Second Chair',
            'type' => 'stock',
            'attribute_ids' => [],
            'variants' => [
                ['value_ids' => [], 'sku' => 'CHR-01', 'barcode' => '8901234567890', 'price' => '120.00', 'min_price' => '100.00', 'online_price' => '', 'is_active' => true],
            ],
        ])
        ->assertSessionHasErrors(['variants.0.sku', 'variants.0.barcode']);
});

test('minimum price rules are enforced on product save, bulk price change and price floor overrides', function () {
    $admin = User::factory()->admin()->create();
    $location = Location::factory()->create();
    $manager = User::factory()->manager()->at($location)->withPin('2468')->create();
    $cashier = User::factory()->role(Role::Cashier)->at($location)->create();
    $category = Category::factory()->create();

    // 1. Product form refuses min_price > price or online_price < min_price.
    $this->actingAs($admin)
        ->post(route('products.store'), [
            'category_id' => $category->id,
            'name' => 'Bad Price Sofa',
            'type' => 'stock',
            'attribute_ids' => [],
            'variants' => [
                ['value_ids' => [], 'sku' => 'BAD-01', 'price' => '1000.00', 'min_price' => '1200.00', 'online_price' => '900.00', 'is_active' => true],
            ],
        ])
        ->assertSessionHasErrors(['variants.0.min_price', 'variants.0.online_price']);

    // 2. Valid product logs initial prices and updates in price_change_logs.
    $product = app(ProductService::class)->save(
        new Product,
        ['category_id' => $category->id, 'name' => 'Good Sofa', 'type' => ProductType::Stock],
        [],
        [['value_ids' => [], 'sku' => 'GOOD-01', 'price' => 100000, 'min_price' => 90000, 'online_price' => null, 'reorder_level' => 0, 'reorder_qty' => 0, 'is_active' => true]],
    );
    $variant = $product->variants()->firstOrFail();

    // Reducing price by -15% without reducing min_price is blocked in preview and apply.
    $this->actingAs($admin)
        ->postJson(route('products.bulk-price-preview'), [
            'product_ids' => [$product->id],
            'percent' => '-15',
            'fields' => ['price'],
            'round_to' => 1,
        ])
        ->assertOk()
        ->assertJsonCount(1, 'blocked');

    // Increasing both price and min_price by +10% succeeds and writes 'bulk' price_change_logs.
    $this->actingAs($admin)
        ->post(route('products.bulk'), [
            'action' => 'price_change',
            'product_ids' => [$product->id],
            'percent' => '10',
            'fields' => ['price', 'min_price'],
            'round_to' => 1,
        ])
        ->assertSessionHas('success');

    $variant->refresh();
    expect($variant->price)->toBe(110000)
        ->and($variant->min_price)->toBe(99000)
        ->and(PriceChangeLog::where('product_variant_id', $variant->id)->where('source', 'bulk')->count())->toBe(2);

    // 3. PriceFloor: at/above min_price needs no approval; below min_price needs manager PIN for cashier.
    $floor = new PriceFloor;
    expect($floor->authorise($cashier, $variant, 99000, $location->id))->toBeNull();
    expect($floor->authorise($manager, $variant, 95000, $location->id)?->id)->toBe($manager->id);
    expect($floor->authorise($cashier, $variant, 95000, $location->id, $manager->id, '2468')?->id)->toBe($manager->id);

    expect(fn () => $floor->authorise($cashier, $variant, 95000, $location->id, $manager->id, '0000'))
        ->toThrow(ApprovalRefused::class);
});

test('categories enforce a maximum depth of 3 levels', function () {
    $admin = User::factory()->admin()->create();

    $l1 = Category::factory()->create(['name' => 'Level 1', 'slug' => 'level-1']);
    $l2 = Category::factory()->create(['parent_id' => $l1->id, 'name' => 'Level 2', 'slug' => 'level-2']);
    $l3 = Category::factory()->create(['parent_id' => $l2->id, 'name' => 'Level 3', 'slug' => 'level-3']);

    $this->actingAs($admin)
        ->post(route('categories.store'), [
            'parent_id' => $l3->id,
            'name' => 'Level 4',
            'is_active' => true,
        ])
        ->assertSessionHasErrors('parent_id');
});

test('permissions: managers_edit_catalog controls manager write access and cost is absent for sales and storekeeper roles', function () {
    $location = Location::factory()->create();
    $admin = User::factory()->admin()->create();
    $manager = User::factory()->manager()->at($location)->create();
    $cashier = User::factory()->role(Role::Cashier)->at($location)->create();
    $storekeeper = User::factory()->role(Role::Storekeeper)->at($location)->create();
    $accountant = User::factory()->role(Role::Accountant)->at($location)->create();

    $supplier = Supplier::create(['name' => 'Damro']);
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'price' => 15000000,
        'min_price' => 14000000,
        'average_cost' => 9500000,
        'last_cost' => 9800000,
    ]);
    $variant->suppliers()->attach($supplier->id, ['supplier_sku' => 'DM-1', 'last_cost' => 9800000, 'is_preferred' => true]);

    // Cashier (sales role) and Storekeeper can view the product list and product page,
    // and the cost props are completely absent from the Inertia payload.
    foreach ([$cashier, $storekeeper] as $viewer) {
        $this->actingAs($viewer)
            ->get(route('products.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Products/Index')
                ->where('canViewCost', false)
                ->where('canEdit', false)
                ->where('products.data.0.variants.0.price', 15000000)
                ->missing('products.data.0.variants.0.average_cost')
                ->missing('products.data.0.variants.0.last_cost'));

        $this->actingAs($viewer)
            ->get(route('products.edit', $product->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Products/Form')
                ->where('canViewCost', false)
                ->where('product.variants.0.price', 15000000)
                ->missing('product.variants.0.average_cost')
                ->missing('product.variants.0.last_cost')
                ->missing('product.variants.0.suppliers.0.last_cost')
                ->missing('product.variants.0.suppliers.0.last_cost_input'));

        $this->actingAs($viewer)
            ->post(route('categories.store'), ['name' => 'Blocked'])
            ->assertForbidden();
    }

    // Admin, Manager, and Accountant DO receive cost props.
    foreach ([$admin, $manager, $accountant] as $costViewer) {
        $this->actingAs($costViewer)
            ->get(route('products.edit', $product->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canViewCost', true)
                ->where('product.variants.0.average_cost', 9500000)
                ->where('product.variants.0.last_cost', 9800000)
                ->where('product.variants.0.suppliers.0.last_cost', 9800000));
    }

    // Manager can edit catalog by default, but is forbidden when managers_edit_catalog is disabled.
    $this->actingAs($manager)
        ->post(route('categories.store'), ['name' => 'Allowed Category', 'is_active' => true])
        ->assertRedirect();

    CompanySetting::current()->update(['managers_edit_catalog' => false]);

    $this->actingAs($manager)
        ->post(route('categories.store'), ['name' => 'Forbidden Category', 'is_active' => true])
        ->assertForbidden();

    $this->actingAs($admin)
        ->post(route('categories.store'), ['name' => 'Admin Still Allowed', 'is_active' => true])
        ->assertRedirect();
});

test('product images can be uploaded as WebP with thumbnails, reordered and deleted, and barcode labels render SVG', function () {
    Storage::fake('public');
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'LBL-SOFA-01']);

    $this->actingAs($admin)
        ->post(route('products.images.store', $product), [
            'images' => [
                UploadedFile::fake()->image('front.jpg', 800, 600),
                UploadedFile::fake()->image('side.png', 800, 600),
            ],
            'product_variant_id' => $variant->id,
        ])
        ->assertRedirect();

    $images = $product->images()->get();
    expect($images)->toHaveCount(2)
        ->and($images[0]->image_path)->toEndWith('.webp')
        ->and($images[0]->thumbnail_path)->toEndWith('_thumb.webp');

    Storage::disk('public')->assertExists([$images[0]->image_path, $images[0]->thumbnail_path]);

    // Reorder images
    $this->actingAs($admin)
        ->post(route('products.images.reorder', $product), ['ids' => [$images[1]->id, $images[0]->id]])
        ->assertRedirect();

    expect($product->images()->pluck('id')->all())->toBe([$images[1]->id, $images[0]->id]);

    // Delete first image
    $this->actingAs($admin)
        ->delete(route('products.images.destroy', [$product, $images[0]]))
        ->assertRedirect();

    Storage::disk('public')->assertMissing([$images[0]->image_path, $images[0]->thumbnail_path]);

    // Labels screen and search endpoint return Code 128 SVG
    $this->actingAs($admin)
        ->get(route('products.labels', ['variant_ids' => [$variant->id]]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Products/Labels')
            ->where('initialVariants.0.sku', 'LBL-SOFA-01')
            ->where('initialVariants.0.barcode_svg', fn (string $svg) => str_contains($svg, '<svg')));
});
