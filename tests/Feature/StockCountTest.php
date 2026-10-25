<?php

use App\Enums\MovementType;
use App\Enums\ProductType;
use App\Enums\StockCountScope;
use App\Enums\StockCountStatus;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\Catalog\ProductService;
use App\Services\Inventory\StockCountService;
use App\Services\Inventory\StockService;
use App\Support\Quantity;
use Inertia\Testing\AssertableInertia as Assert;

function createCountVariant(string $sku, string $barcode): ProductVariant
{
    $category = Category::query()->first() ?? Category::create(['name' => 'Dining', 'slug' => 'dining']);

    $product = app(ProductService::class)->save(
        new Product,
        [
            'category_id' => $category->id,
            'name' => 'Count Item '.$sku,
            'slug' => strtolower($sku),
            'type' => ProductType::Stock,
            'is_active' => true,
        ],
        [],
        [
            [
                'value_ids' => [],
                'sku' => $sku,
                'barcode' => $barcode,
                'price' => 5000000,
                'min_price' => 4500000,
                'online_price' => null,
                'reorder_level' => 0,
                'reorder_qty' => 0,
                'is_active' => true,
            ],
        ],
    );

    return $product->variants()->firstOrFail();
}

test('blind stock count hides system qty during counting, supports barcode scanning, and posts count gain/loss on manager approval', function () {
    $loc = Location::factory()->create(['code' => 'CNT']);
    $storekeeper = User::factory()->storekeeper()->create(['home_location_id' => $loc->id]);
    $storekeeper->assignLocations($loc->id);

    $manager = User::factory()->manager()->create(['home_location_id' => $loc->id]);
    $manager->assignLocations($loc->id);

    $v1 = createCountVariant('CNT-ITEM-01', '890000000001');
    $v2 = createCountVariant('CNT-ITEM-02', '890000000002');

    // System has 5 of v1 and 3 of v2
    app(StockService::class)->moveMany([
        ['product_variant_id' => $v1->id, 'location_id' => $loc->id, 'type' => MovementType::Opening, 'quantity' => Quantity::fromUnits(5), 'unit_cost' => 2000000],
        ['product_variant_id' => $v2->id, 'location_id' => $loc->id, 'type' => MovementType::Opening, 'quantity' => Quantity::fromUnits(3), 'unit_cost' => 3000000],
    ]);

    $service = app(StockCountService::class);

    $count = $service->start([
        'location_id' => $loc->id,
        'scope' => StockCountScope::Full,
        'blind' => true,
        'note' => 'Blind cycle count',
    ], $storekeeper);

    // While counting in blind mode, the Inertia response hides system_qty and variance_qty
    $this->actingAs($storekeeper)
        ->get(route('stock.counts.show', $count))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Stock/Counts/Show')
            ->where('count.hide_system_qty', true)
            ->missing('lines.0.system_qty')
            ->missing('lines.0.variance_qty'));

    // Scan v1 barcode 4 times -> counted = 4 (variance = -1 loss)
    for ($i = 0; $i < 4; $i++) {
        $service->scan($count, '890000000001', $storekeeper);
    }

    // Scan v2 by SKU with step 5 -> counted = 5 (variance = +2 gain)
    $service->scan($count, 'CNT-ITEM-02', $storekeeper, Quantity::fromUnits(5));

    $service->submitForReview($count, $storekeeper);
    expect($count->fresh()->status)->toBe(StockCountStatus::Review);

    // Storekeeper cannot post the count
    expect(fn () => $service->post($count, $storekeeper))
        ->toThrow(InvalidArgumentException::class, 'Only a manager or admin can post a stock count.');

    // Manager posts the count
    $service->post($count, $manager);

    expect($count->fresh()->status)->toBe(StockCountStatus::Posted)
        ->and(StockLevel::query()->where('location_id', $loc->id)->where('product_variant_id', $v1->id)->value('on_hand'))
        ->toBe(Quantity::fromUnits(4))
        ->and(StockLevel::query()->where('location_id', $loc->id)->where('product_variant_id', $v2->id)->value('on_hand'))
        ->toBe(Quantity::fromUnits(5));

    $this->artisan('stock:verify')->assertSuccessful();
});
