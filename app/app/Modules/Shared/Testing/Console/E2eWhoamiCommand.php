<?php

declare(strict_types=1);

namespace App\Modules\Shared\Testing\Console;

use App\Modules\Shared\Testing\E2eMode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The preflight every Playwright run calls first: it proves which container
 * and database the suite is about to drive. The Node helper aborts on any
 * answer but `arovolife_e2e`.
 */
final class E2eWhoamiCommand extends Command
{
    protected $signature = 'e2e:whoami';

    protected $description = 'Print the e2e flag, environment, connected database and frozen clock as JSON';

    public function handle(): int
    {
        $database = E2eMode::assertSafe($this->laravel);

        // Ask the server too, so a connection that silently points elsewhere shows up.
        $serverSide = (string) DB::selectOne('SELECT DATABASE() AS d')->d;

        $this->line((string) json_encode([
            'e2e' => E2eMode::requested(),
            'env' => $this->laravel->environment(),
            'database' => $database,
            'server_database' => $serverSide,
            'clock' => E2eMode::readClock()?->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES));

        return $serverSide === $database ? self::SUCCESS : self::FAILURE;
    }
}
