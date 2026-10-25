<?php

namespace App\Console\Commands;

use App\Jobs\SendDailyReport;
use App\Models\CompanySetting;
use App\Services\Reports\DailyOwnerReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Safety net: at company_settings.daily_email_time (Asia/Colombo), emails yesterday's report if it
 * wasn't sent when the last location closed the day. Safe to run every few minutes.
 */
#[Signature('reports:daily-email')]
#[Description('Email the owner\'s daily report if it has not gone out yet')]
class SendDailyReports extends Command
{
    public function handle(DailyOwnerReport $reports): int
    {
        $settings = CompanySetting::current();
        $local = now()->setTimezone(config('app.timezone'));

        if ($local->format('H:i') < ($settings->daily_email_time ?: '07:00') || empty($settings->report_emails)) {
            return self::SUCCESS;
        }

        $date = CarbonImmutable::parse($local->toDateString())->subDay()->toDateString();

        if (DB::table('daily_report_sends')->whereDate('business_date', $date)->exists() || ! $reports->hadActivity($date)) {
            return self::SUCCESS;
        }

        SendDailyReport::dispatchSync($date);
        $this->info("Emailed the report for {$date}.");

        return self::SUCCESS;
    }
}
