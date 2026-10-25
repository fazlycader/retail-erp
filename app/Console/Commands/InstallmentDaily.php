<?php

namespace App\Console\Commands;

use App\Services\Installments\InstallmentService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('installments:daily')]
#[Description('Apply installment late fees and queue due or overdue reminders')]
class InstallmentDaily extends Command
{
    public function handle(InstallmentService $installments): int
    {
        $fees = $installments->chargeLateFees();
        $reminders = $installments->sendReminders();
        $this->info("Recorded {$fees} late fee(s) and queued {$reminders} reminder(s).");

        return self::SUCCESS;
    }
}
