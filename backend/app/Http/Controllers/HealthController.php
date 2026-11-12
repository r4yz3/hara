<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /**
     * Reports only up/down per dependency. Error details go to the log, never
     * to the response.
     */
    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->passes(fn () => DB::connection()->select('select 1')),
            'redis' => $this->passes(fn () => Redis::connection()->command('ping')),
        ];

        $ready = ! in_array(false, $checks, true);

        return response()->json(
            ['status' => $ready ? 'ready' : 'unavailable', 'checks' => $checks],
            $ready ? 200 : 503,
        );
    }

    private function passes(callable $check): bool
    {
        try {
            $check();

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
