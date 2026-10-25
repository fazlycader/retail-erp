<?php

namespace App\Jobs;

use App\Mail\DailyReportMail;
use App\Models\CompanySetting;
use App\Services\Reports\DailyOwnerReport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails the owner's daily report for a business date to company_settings.report_emails.
 * Sent once per date (daily_report_sends): after the last location closes the day, or by the
 * reports:daily-email safety net at daily_email_time (then it lists the locations not closed).
 */
class SendDailyReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $date) {}

    public function handle(DailyOwnerReport $reports): void
    {
        $recipients = array_values(array_filter(CompanySetting::current()->report_emails ?? []));

        if ($recipients === []) {
            return;
        }

        $report = $reports->build($this->date);

        // Claim the date first: a second run (cron retry, another close) finds the row and stops.
        $claimed = DB::table('daily_report_sends')->insertOrIgnore([
            'business_date' => $this->date,
            'sent_at' => now(),
            'unclosed_locations' => json_encode($report['unclosed']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! $claimed) {
            return;
        }

        try {
            Mail::to($recipients)->send(new DailyReportMail($report, CompanySetting::current()->name));
        } catch (Throwable $e) {
            DB::table('daily_report_sends')->where('business_date', $this->date)->delete(); // let the queue retry
            throw $e;
        }
    }
}
