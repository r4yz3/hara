<?php

namespace Tests\Contract;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Keeps openapi/openapi.yaml and the registered routes equal (ADR-010): no
 * undocumented route, no documented route that does not exist.
 *
 * This checks paths, methods and documented field names against real
 * responses. It is not full schema validation; that arrives with a
 * validator library in Sprint 5.
 */
class OpenApiDocumentTest extends TestCase
{
    /** @return array<string, mixed> */
    private function document(): array
    {
        /** @var array<string, mixed> $document */
        $document = Yaml::parseFile(base_path('openapi/openapi.yaml'));

        return $document;
    }

    public function test_document_is_openapi_3_1(): void
    {
        $this->assertSame('3.1.0', $this->document()['openapi']);
    }

    public function test_documented_operations_equal_registered_routes(): void
    {
        $documented = [];
        foreach ($this->document()['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                // "parameters" at path level is shared configuration, not an operation.
                if ($method !== 'parameters') {
                    $documented[] = strtoupper((string) $method).' '.$path;
                }
            }
        }

        $registered = collect(Route::getRoutes()->getRoutes())
            ->flatMap(fn (RoutingRoute $route) => collect($route->methods())
                ->reject(fn (string $method): bool => $method === 'HEAD')
                ->map(fn (string $method): string => $method.' /'.ltrim($route->uri(), '/')))
            ->all();

        sort($documented);
        sort($registered);

        $this->assertSame($registered, $documented);
    }

    public function test_every_operation_has_an_id_and_documents_its_errors(): void
    {
        foreach ($this->document()['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if ($method === 'parameters') {
                    continue;
                }
                $this->assertNotEmpty($operation['operationId'] ?? null, "{$method} {$path} has no operationId");
                $this->assertNotEmpty($operation['responses'] ?? null, "{$method} {$path} documents no responses");
            }
        }
    }

    public function test_error_codes_in_the_document_match_the_renderer(): void
    {
        $documented = $this->document()['components']['schemas']['ErrorResponse']['properties']['error']['properties']['code']['enum'];

        $source = (string) file_get_contents(app_path('Support/Errors/ApiErrorRenderer.php'))
            .(string) file_get_contents(app_path('Support/Errors/ApiError.php'));
        preg_match_all("/'([A-Z][A-Z_]{3,})'/", $source, $matches);

        $this->assertEqualsCanonicalizing(array_values(array_unique($matches[1])), $documented);
    }

    public function test_live_error_and_health_bodies_use_only_documented_fields(): void
    {
        $schemas = $this->document()['components']['schemas'];

        $error = $this->getJson('/api/v1/does-not-exist')->json('error');
        $this->assertSame([], array_diff(array_keys($error), array_keys($schemas['ErrorResponse']['properties']['error']['properties'])));

        $ready = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->getJson('/health/ready')->json();
        $this->assertSame(array_keys($schemas['Readiness']['properties']), array_keys($ready));
    }
}
