<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Console\Commands\Concerns\RebuildsPeriod;
use App\Modules\Compensation\Services\Rebuild\RebuildKind;
use Illuminate\Console\Command;

/**
 * Rebuild one night: wipe the cut-off day it owns and run the nightly run again
 * from scratch (ADR-0016, D4).
 *
 * The period is the NIGHT, not the day — `--date=2026-09-18` rebuilds the
 * cut-off for the 17th, because that is how the nightly run is dated and how its
 * run row reads. Only while that night is the newest one: the carry-forward
 * store is rolling, so the deadline is the next nightly run at 00:05 IST (D11,
 * R-91). Past it the refusal says so and names the windowed recompute, which
 * runs on dev and staging only.
 *
 * Developer-only. `--actor` must be a user holding the `developer` role; there
 * is no admin surface for this and no other role may reach it.
 */
final class RebuildNightCommand extends Command
{
    use RebuildsPeriod;

    protected $signature = 'compensation:rebuild-night
                            {--date= : The NIGHT to rebuild (YYYY-MM-DD); the cut-off rebuilt is the day before}
                            {--actor= : REQUIRED from a shell — user id of the developer deciding the rebuild}
                            {--yes : Skip the interactive confirmation (the queued job passes it)}';

    protected $description = 'Wipe one night\'s cut-off and run the nightly run for it again (developer only)';

    protected function kind(): RebuildKind
    {
        return RebuildKind::Night;
    }
}
