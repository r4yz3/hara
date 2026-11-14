<?php

namespace Tests\Feature\Console;

use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Mailer\SentMessage;
use Tests\TestCase;

/**
 * `mail:test`: the operator's check that outbound mail works.
 */
class MailTestCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('mail-test-command');
        config(['mail.default' => 'array']);
    }

    /** @return Collection<int, SentMessage> */
    private function sent(): Collection
    {
        $transport = Mail::mailer('array')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        return $transport->messages();
    }

    public function test_it_sends_one_plain_text_message_to_the_address(): void
    {
        $code = Artisan::call('mail:test', ['address' => 'operator@example.test']);

        $this->assertSame(0, $code, Artisan::output());
        $messages = $this->sent();
        $this->assertCount(1, $messages);
        $this->assertSame('operator@example.test', $messages[0]->getEnvelope()->getRecipients()[0]->getAddress());
    }

    public function test_it_rejects_an_invalid_address_without_sending(): void
    {
        $code = Artisan::call('mail:test', ['address' => 'not-an-address']);

        $this->assertSame(2, $code);
        $this->assertCount(0, $this->sent());
    }

    public function test_it_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(0, Artisan::call('mail:test', ['address' => 'operator@example.test']));
        }

        $this->assertSame(1, Artisan::call('mail:test', ['address' => 'operator@example.test']));
        $this->assertCount(5, $this->sent());
    }

    public function test_it_never_prints_the_smtp_password(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.password' => 'secret-api-key-value', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);

        Artisan::call('mail:test', ['address' => 'operator@example.test']);

        $this->assertStringNotContainsString('secret-api-key-value', Artisan::output());
    }

    public function test_it_is_not_reachable_over_http(): void
    {
        $this->assertNull(collect(app('router')->getRoutes()->getRoutes())->first(fn ($r) => str_contains($r->uri(), 'mail')));
    }
}
