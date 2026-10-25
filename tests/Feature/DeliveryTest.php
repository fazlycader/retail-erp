<?php

use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\DeliveryRun;
use App\Models\DeliveryVehicle;
use App\Models\DeliveryZone;
use App\Models\InstallationJob;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Delivery\DeliveryService;

test('delivery zones calculate flat, percentage and free-above fees in cents', function () {
    $service = app(DeliveryService::class);

    expect($service->fee(new DeliveryZone(['fee_rule' => 'flat', 'fee_cents' => 250000]), 1000000))->toBe(250000)
        ->and($service->fee(new DeliveryZone(['fee_rule' => 'order_value', 'fee_cents' => 500]), 1000000))->toBe(50000)
        ->and($service->fee(new DeliveryZone(['fee_rule' => 'free_above', 'fee_cents' => 250000, 'free_above_cents' => 1000000]), 1000000))->toBe(0);
});

test('vehicle capacity warnings include item count and product cubic dimensions', function () {
    $line = new DeliveryLine(['quantity' => 2000]);
    $product = new Product(['width_mm' => 1000, 'depth_mm' => 1000, 'height_mm' => 1000]);
    $variant = new ProductVariant;
    $variant->setRelation('product', $product);
    $line->setRelation('variant', $variant);
    $delivery = new Delivery;
    $delivery->id = 123;
    $delivery->setRelation('lines', collect([$line]));
    $vehicle = new DeliveryVehicle(['name' => 'Small van', 'capacity_items' => 1, 'capacity_cubic_mm' => 500000000]);

    $warnings = app(DeliveryService::class)->capacityWarnings($vehicle, today()->toDateString(), [$delivery]);

    expect($warnings)->toHaveCount(2)
        ->and($warnings[0])->toContain('2 items')
        ->and($warnings[1])->toContain('cubic capacity');
});

test('delivery document numbers use a gap-free location counter', function () {
    $location = Location::query()->create(['name' => 'Kandy Showroom', 'code' => 'KDY', 'type' => 'showroom', 'is_active' => true]);
    $service = app(DeliveryService::class);

    expect($service->nextNumber($location->id))->toEndWith('KDY-000001')
        ->and($service->nextNumber($location->id))->toEndWith('KDY-000002');
});

test('delivery records have stable polymorphic aliases for audit and stock ledgers', function () {
    expect((new Delivery)->getMorphClass())->toBe('delivery')
        ->and((new DeliveryRun)->getMorphClass())->toBe('delivery_run')
        ->and((new InstallationJob)->getMorphClass())->toBe('installation_job');
});
