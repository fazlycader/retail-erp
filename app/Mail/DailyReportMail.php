<?php

namespace App\Mail;

use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The owner's daily email (resources/views/emails/daily-report.blade.php). */
class DailyReportMail extends Mailable
{
    /**
     * @param  array<string, mixed>  $report
     */
    public function __construct(public array $report, public string $company) {}

    public function envelope(): Envelope
    {
        $date = CarbonImmutable::parse($this->report['business_date'])->format('D j M Y');

        return new Envelope(subject: "{$this->company}: sales for {$date}".($this->report['unclosed'] ? ' (not all locations closed)' : ''));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.daily-report', with: [
            'r' => $this->report,
            'company' => $this->company,
            'url' => route('reports.show', ['report' => 'sales', 'from' => $this->report['business_date'], 'to' => $this->report['business_date'], 'location' => 'all']),
        ]);
    }
}
