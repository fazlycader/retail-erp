<?php

namespace App\Console\Commands;

use App\Services\Woo\WooPusher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('woo:push {--all : Mark every online product dirty first} {--budget=45 : Seconds to work before stopping}')]
#[Description('Send dirty products (details, prices, stock) and order statuses to WooCommerce')]
class WooPush extends Command
{
    public function handle(WooPusher $pusher): int
    {
        if ($this->option('all')) {
            $this->info(WooPusher::markAll().' product(s) marked.');
        }

        $s = $pusher->run((int) $this->option('budget'));
        $this->info("Products {$s['products']}, variations {$s['variations']}, unchanged {$s['skipped']}, order statuses {$s['orders']}, failed {$s['failed']}.");

        return $s['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
