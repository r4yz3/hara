<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Operator check that outbound mail works (staging-setup.md, section 15).
 * Console only, one fixed plain-text message, rate limited. Not in
 * ProductionGuard::REPAIR_COMMANDS: it must refuse to run where the guard
 * rejects the configuration. It never prints the SMTP password.
 */
#[AsCommand(name: 'mail:test', description: 'Send one test email through the configured mailer')]
class MailTestCommand extends Command
{
    private const LIMIT_KEY = 'mail-test-command';

    private const MAX_PER_HOUR = 5;

    protected $signature = 'mail:test {address : The address that receives the test message}';

    public function handle(): int
    {
        $address = trim((string) $this->argument('address'));

        $validator = Validator::make(['address' => $address], ['address' => ['required', 'string', 'max:254', 'email:rfc']]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first('address'));

            return self::INVALID;
        }

        if (RateLimiter::tooManyAttempts(self::LIMIT_KEY, self::MAX_PER_HOUR)) {
            $this->error('At most '.self::MAX_PER_HOUR.' test emails per hour. Try again in '.RateLimiter::availableIn(self::LIMIT_KEY).' seconds.');

            return self::FAILURE;
        }
        RateLimiter::hit(self::LIMIT_KEY, 3600);

        $mailer = (string) config('mail.default');
        $this->line('Mailer: '.$mailer.($mailer === 'smtp' ? ' ('.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port').')' : ''));
        $this->line('From: '.config('mail.from.address'));

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->warn('This mailer does not deliver anything: the message goes to the log or is dropped.');
        }

        try {
            Mail::raw(
                'This is a test message from '.config('app.name').' ('.config('app.env')."). If you can read it, outbound email works.\n",
                fn ($message) => $message->to($address)->subject('Test email from '.config('app.name')),
            );
        } catch (Throwable $e) {
            $this->error('Sending failed: '.class_basename($e).': '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Handed to the mailer. Check the inbox (and spam) of the address you gave.');

        return self::SUCCESS;
    }
}
