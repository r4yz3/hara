<?php

namespace Tests\Integration;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

abstract class PostgresConcurrencyTestCase extends TestCase
{
    use DatabaseMigrations;

    /** @param list<string> $arguments */
    protected function startContender(string $body, array $arguments): string
    {
        $session = strtolower((string) Str::ulid());
        $bootstrap = <<<'PHP'
            require 'tests/bootstrap.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            Illuminate\Support\Facades\DB::selectOne("SELECT set_config('application_name', ?, false)", [$argv[1]]);
            PHP;
        $worker = new Process([PHP_BINARY, '-r', $bootstrap."\n".$body, $session, ...$arguments], base_path(), timeout: 20);

        try {
            $worker->start();
            $deadline = hrtime(true) + 10_000_000_000;
            do {
                DB::selectOne('SELECT pg_stat_clear_snapshot()');
                $blocked = DB::selectOne(
                    'SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE application_name = ?
                        AND pg_backend_pid() = ANY(pg_blocking_pids(pid))) AS waiting',
                    [$session],
                )->waiting;
                if ($blocked) {
                    DB::commit();
                    $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());

                    return $worker->getOutput();
                }
                usleep(20_000);
            } while ($worker->isRunning() && hrtime(true) < $deadline);

            $this->fail('The contender never waited on the first transaction. '.$worker->getErrorOutput());
        } finally {
            $worker->stop();
        }
    }
}
