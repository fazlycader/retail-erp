<?php

namespace App\Console\Commands;

use App\Services\Woo\WooReconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('woo:reconcile {--budget=45 : Seconds to work before stopping}')]
#[Description('Process WooCommerce orders the webhooks missed and re-push stock that differs')]
class WooReconcile extends Command
{
    public function handle(WooReconciler $reconciler): int
    {
        $s = $reconciler->run((int) $this->option('budget'));
        $this->info("Orders {$s['orders']}, failed {$s['failed']}, stock differences {$s['stock_mismatches']}.");

        return self::SUCCESS;
    }
}
