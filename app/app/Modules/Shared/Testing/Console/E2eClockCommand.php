<?php

declare(strict_types=1);

namespace App\Modules\Shared\Testing\Console;

use App\Modules\Shared\Testing\E2eMode;
use Illuminate\Console\Command;

/**
 * Show or move the frozen e2e clock. Every web request and e2e command reads
 * it, so a spec can step the platform to a fixed instant (end of a cut-off,
 * day 31 of cooling-off…) and get the same result on any real calendar day.
 */
final class E2eClockCommand extends Command
{
    protected $signature = 'e2e:clock {instant? : An instant in Asia/Kolkata, e.g. "2026-06-15 23:59:00"}';

    protected $description = 'Show or set the frozen clock of the e2e stack';

    public function handle(): int
    {
        E2eMode::assertSafe($this->laravel);

        $instant = $this->argument('instant');

        if (is_string($instant) && $instant !== '') {
            E2eMode::writeClock(E2eMode::parseInstant($instant));
        }

        $this->line(E2eMode::readClock()?->toIso8601String() ?? 'unset');

        return self::SUCCESS;
    }
}
