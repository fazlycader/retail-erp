<?php

namespace Database\Seeders;

use App\Enums\LocationType;
use App\Models\Location;
use Illuminate\Database\Seeder;

/** The two starting locations. Safe to run again (matched by code). */
class LocationSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'HO', 'name' => 'Head Office Warehouse', 'type' => LocationType::Warehouse],
            ['code' => 'MS', 'name' => 'Main Showroom', 'type' => LocationType::Showroom],
        ] as $location) {
            Location::query()->firstOrCreate(['code' => $location['code']], $location);
        }
    }
}
