<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class TestMail extends Command
{
    protected $signature = 'app:test-mail {to : Address to send the test email to}';

    protected $description = 'Send a test email to check the SMTP settings';

    public function handle(): int
    {
        $to = (string) $this->argument('to');

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error("\"{$to}\" is not an email address.");

            return self::FAILURE;
        }

        try {
            Mail::raw('This is a test email from '.config('app.name').' ('.config('app.url').'). Mail is working.', fn ($message) => $message->to($to)->subject(config('app.name').': test email'));
        } catch (Throwable $e) {
            $this->error('Sending failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Sent to {$to} via ".config('mail.default').' ('.config('mail.mailers.smtp.host').'). Check the inbox and the spam folder.');

        return self::SUCCESS;
    }
}
