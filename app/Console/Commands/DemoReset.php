<?php

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Wipe the database and load the demo data (DemoSeeder grows with every build step), with
 * --days of simulated trading history (DemoHistorySeeder) for demos and training.
 */
#[Signature('demo:reset {--force : Skip the confirmation} {--days=30 : Days of trading history to simulate (0 = none)}')]
#[Description('Drop every table, migrate and load demo data (never in production)')]
class DemoReset extends Command
{
    public function handle(): int
    {
        if ($this->laravel->isProduction()) {
            $this->error('demo:reset is not allowed in production.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('This deletes ALL data in '.config('database.connections.'.config('database.default').'.database').'. Continue?')) {
            return self::FAILURE;
        }

        $this->call('migrate:fresh', ['--force' => true]);
        config(['demo.history_days' => max(0, (int) $this->option('days'))]);
        $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);

        $this->newLine();
        $this->info('Demo data loaded. Sign in with any of these (password "password", PIN 1234):');
        $this->table(['Email', 'Role'], [
            ['admin@example.com', 'Admin (all locations)'],
            ['manager@example.com', 'Manager: Main Showroom + Kandy'],
            ['warehouse.manager@example.com', 'Manager: Head Office Warehouse'],
            ['cashier@example.com', 'Cashier: Main Showroom'],
            ['kandy.cashier@example.com', 'Cashier: Kandy Showroom'],
            ['storekeeper@example.com', 'Storekeeper: Warehouse + Main Showroom'],
            ['accountant@example.com', 'Accountant: all three'],
        ]);

        return self::SUCCESS;
    }
}
