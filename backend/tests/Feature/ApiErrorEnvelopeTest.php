<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiErrorEnvelopeTest extends TestCase
{
    public function test_unknown_api_route_returns_the_error_envelope(): void
    {
        $response = $this->getJson('/api/v1/does-not-exist')->assertStatus(404);

        $this->assertSame(['error'], array_keys($response->json()));
        $this->assertSame(['code', 'message', 'request_id'], array_keys($response->json('error')));
        $response->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_every_api_response_carries_a_request_id_header_matching_the_body(): void
    {
        $response = $this->getJson('/api/v1/does-not-exist');

        $header = $response->headers->get('X-Request-Id');
        $this->assertMatchesRegularExpression('/\A[0-9a-z]{26}\z/', (string) $header);
        $this->assertSame($header, $response->json('error.request_id'));
    }

    public function test_a_well_formed_incoming_request_id_is_kept_and_a_hostile_one_is_replaced(): void
    {
        $kept = $this->withHeader('X-Request-Id', 'trace-abc_123')->getJson('/api/v1/does-not-exist');
        $this->assertSame('trace-abc_123', $kept->headers->get('X-Request-Id'));

        $replaced = $this->withHeader('X-Request-Id', "bad\r\nSet-Cookie: x=1 <script>")->getJson('/api/v1/does-not-exist');
        $this->assertMatchesRegularExpression('/\A[0-9a-z]{26}\z/', (string) $replaced->headers->get('X-Request-Id'));
    }

    public function test_wrong_method_is_reported_without_internals(): void
    {
        $response = $this->deleteJson('/api/v1/auth/login')->assertStatus(405);

        $response->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
        $this->assertStringNotContainsString('Symfony', (string) $response->getContent());
    }

    public function test_unexpected_errors_reveal_nothing_even_with_debug_on(): void
    {
        config(['app.debug' => true]);
        Route::get('/api/v1/_boom', fn () => throw new \RuntimeException('secret detail: DB_PASSWORD=hunter2'))
            ->middleware('api');

        $response = $this->getJson('/api/v1/_boom')->assertStatus(500);

        $response->assertJsonPath('error.code', 'SERVER_ERROR');
        $this->assertSame(['code', 'message', 'request_id'], array_keys($response->json('error')));
        $this->assertStringNotContainsString('hunter2', (string) $response->getContent());
        $this->assertStringNotContainsString('RuntimeException', (string) $response->getContent());
    }
}
