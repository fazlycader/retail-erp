<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Everything a fresh install needs: locations, company settings and one admin.
     * Demo data lives in DemoSeeder (php artisan demo:reset).
     */
    public function run(): void
    {
        $this->call([
            LocationSeeder::class,
            CompanySettingSeeder::class,
            AdminSeeder::class,
        ]);
    }
}
