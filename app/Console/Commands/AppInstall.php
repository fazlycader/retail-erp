<?php

namespace App\Console\Commands;

use Database\Seeders\CompanySettingSeeder;
use Database\Seeders\LocationSeeder;
use Illuminate\Console\Command;

/**
 * First-time setup on a live server: migrations + only the essential seeders (the first locations
 * and company settings; payment methods, cash categories and registers come with the migrations).
 * Never demo data. Safe to run again (the seeders use firstOrCreate).
 */
class AppInstall extends Command
{
    protected $signature = 'app:install';

    protected $description = 'Migrate and seed the essentials for a new installation (no demo data)';

    public function handle(): int
    {
        $this->call('migrate', ['--force' => true]);

        // Payment methods, cash categories and each location's Main Counter come with the migrations.
        foreach ([LocationSeeder::class, CompanySettingSeeder::class] as $seeder) {
            $this->call('db:seed', ['--class' => $seeder, '--force' => true]);
        }

        $this->newLine();
        $this->info('Done. Next: php artisan app:create-admin');

        return self::SUCCESS;
    }
}
