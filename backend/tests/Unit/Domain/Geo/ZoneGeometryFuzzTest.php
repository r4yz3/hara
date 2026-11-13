<?php

namespace Tests\Unit\Domain\Geo;

use App\Domain\Geo\GeometryLimits;
use App\Domain\Geo\InvalidZoneGeometry;
use App\Domain\Geo\ZoneGeometry;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Backlog C-03 fuzz test, structural half. Feeds the validator values of the
 * kinds a decoded JSON body can hold (null, bool, int, float, string, list,
 * map), both fully random and as mutations of a valid shape. Every input
 * must either be rejected with InvalidZoneGeometry or come back as a shape
 * that meets every promise the class makes. Nothing else may escape.
 *
 * Seeded, so a failure is reproducible: the seed is in the failure message,
 * and FUZZ_SEED=<n> replays it.
 */
class ZoneGeometryFuzzTest extends TestCase
{
    private const CASES = 3000;

    private GeometryLimits $limits;

    protected function setUp(): void
    {
        $this->limits = new GeometryLimits(64, 2.0);
    }

    private function fuzzSeed(): int
    {
        $env = getenv('FUZZ_SEED');

        return is_string($env) && ctype_digit($env) ? (int) $env : 20261113;
    }

    public function test_random_and_mutated_input_never_escapes_as_an_unexpected_error(): void
    {
        $seed = $this->fuzzSeed();
        mt_srand($seed);
        $accepted = 0;

        for ($case = 0; $case < self::CASES; $case++) {
            $input = $case % 3 === 0 ? $this->randomValue(4) : $this->mutate($this->validShape());
            if (! is_array($input)) {
                $input = ['type' => 'Polygon', 'coordinates' => $input];
            }

            try {
                $shape = ZoneGeometry::fromGeoJson($input, $this->limits);
            } catch (InvalidZoneGeometry $e) {
                $this->assertNotSame('', $e->reason, "seed {$seed} case {$case}");

                continue;
            } catch (Throwable $e) {
                $this->fail(sprintf('seed %d case %d: %s escaped: %s; input %s', $seed, $case, $e::class, $e->getMessage(), json_encode($input)));
            }

            $accepted++;
            $this->assertKeepsItsPromises($shape, "seed {$seed} case {$case}");
        }

        // The generator must actually reach both outcomes, or the test proves nothing.
        $this->assertGreaterThan(100, $accepted, "seed {$seed}: too few accepted shapes");
        $this->assertLessThan(self::CASES, $accepted, "seed {$seed}: nothing was rejected");
    }

    private function assertKeepsItsPromises(ZoneGeometry $shape, string $where): void
    {
        $out = $shape->toGeoJson();
        $this->assertSame('MultiPolygon', $out['type'], $where);
        $this->assertNotSame([], $out['coordinates'], $where);
        $this->assertLessThanOrEqual($this->limits->maxVertices, $shape->vertexCount, $where);

        $west = $south = INF;
        $east = $north = -INF;
        $count = 0;
        foreach ($out['coordinates'] as $polygon) {
            $this->assertNotSame([], $polygon, $where);
            foreach ($polygon as $ring) {
                $this->assertGreaterThanOrEqual(4, count($ring), $where);
                $this->assertSame($ring[0], $ring[count($ring) - 1], "{$where}: ring not closed");
                foreach ($ring as $position) {
                    $this->assertCount(2, $position, $where);
                    [$longitude, $latitude] = $position;
                    // Checked at runtime: the docblock promises floats, the fuzz test proves it.
                    $this->assertSame(['float', 'float'], [get_debug_type($longitude), get_debug_type($latitude)], $where);
                    $this->assertTrue(is_finite($longitude) && is_finite($latitude), $where);
                    $this->assertTrue($longitude >= -180 && $longitude <= 180, "{$where}: longitude {$longitude}");
                    $this->assertTrue($latitude >= -90 && $latitude <= 90, "{$where}: latitude {$latitude}");
                    $west = min($west, $longitude);
                    $east = max($east, $longitude);
                    $south = min($south, $latitude);
                    $north = max($north, $latitude);
                    $count++;
                }
            }
        }
        $this->assertSame($shape->vertexCount, $count, $where);
        $this->assertLessThanOrEqual($this->limits->maxExtentDegrees, $east - $west, $where);
        $this->assertLessThanOrEqual($this->limits->maxExtentDegrees, $north - $south, $where);

        // Accepting its own output again changes nothing.
        $this->assertSame($out, ZoneGeometry::fromGeoJson($out, $this->limits)->toGeoJson(), "{$where}: not stable");
    }

    /** @return array<string, mixed> */
    private function validShape(): array
    {
        $west = 100 + mt_rand(0, 1000) / 1000;
        $south = 10 + mt_rand(0, 1000) / 1000;
        $size = mt_rand(1, 500) / 1000;
        $ring = [[$west, $south], [$west + $size, $south], [$west + $size, $south + $size], [$west, $south + $size], [$west, $south]];

        return mt_rand(0, 1) === 0
            ? ['type' => 'Polygon', 'coordinates' => [$ring]]
            : ['type' => 'MultiPolygon', 'coordinates' => [[$ring]]];
    }

    /**
     * Applies one to three random edits somewhere in the tree.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function mutate(array $value): array
    {
        $edits = mt_rand(0, 3);
        for ($i = 0; $i < $edits; $i++) {
            $value = $this->mutateAt($value, mt_rand(0, 4));
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    private function mutateAt(array $node, int $depth): array
    {
        if ($node === []) {
            return [$this->randomValue(2)];
        }

        $keys = array_keys($node);
        $key = $keys[mt_rand(0, count($keys) - 1)];

        if ($depth > 0 && is_array($node[$key]) && mt_rand(0, 3) !== 0) {
            $node[$key] = $this->mutateAt($node[$key], $depth - 1);

            return $node;
        }

        switch (mt_rand(0, 7)) {
            case 0: unset($node[$key]);
                break;
            case 1: $node[$key] = $this->randomValue(2);
                break;
            case 2: $node[] = $this->randomValue(1);
                break;
            case 3: $node[$key] = $this->randomNumber();
                break;
            case 4: $node['x'.mt_rand(0, 9)] = $this->randomValue(1);  // turns a list into a map
                break;
            case 5: $node[$key] = is_array($node[$key]) ? array_reverse($node[$key]) : (string) json_encode($node[$key]);
                break;
            case 6: $node[$key] = [$node[$key]];  // one level deeper than expected
                break;
            default: $node[$key] = is_array($node[$key]) && $node[$key] !== [] ? array_slice($node[$key], 0, mt_rand(0, count($node[$key]))) : null;
        }

        return $node;
    }

    private function randomValue(int $depth): mixed
    {
        $kind = mt_rand(0, $depth > 0 ? 8 : 5);

        return match ($kind) {
            0 => null,
            1 => (bool) mt_rand(0, 1),
            2 => mt_rand(-1000, 1000),
            3 => $this->randomNumber(),
            4 => ['', 'Polygon', 'MultiPolygon', 'NaN', '1e400', '100.5', "\u{0000}", str_repeat('a', 50)][mt_rand(0, 7)],
            5 => mt_rand(0, 1) === 0 ? PHP_INT_MAX : PHP_INT_MIN,
            6 => array_map(fn () => $this->randomValue($depth - 1), range(1, mt_rand(0, 6))),
            7 => ['type' => mt_rand(0, 1) === 0 ? 'Polygon' : 'MultiPolygon', 'coordinates' => $this->randomValue($depth - 1)],
            default => [[array_map(fn () => [$this->randomNumber(), $this->randomNumber()], range(1, mt_rand(0, 8)))]],
        };
    }

    private function randomNumber(): float|int
    {
        return match (mt_rand(0, 6)) {
            0 => mt_rand(-181, 181),
            1 => mt_rand(-91000, 91000) / 1000,
            2 => mt_rand(-180000, 180000) / 1000,
            3 => (mt_rand(0, 1) === 0 ? -1 : 1) * 1e308,
            4 => -0.0,
            5 => 100 + mt_rand(0, 2000) / 1000,
            default => 10 + mt_rand(0, 2000) / 1000,
        };
    }
}
