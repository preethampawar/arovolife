<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Console\Commands\Concerns\RebuildsPeriod;
use App\Modules\Compensation\Services\Rebuild\RebuildKind;
use Illuminate\Console\Command;

/**
 * Rebuild one month's crediting: wipe the seven engines' rows for the month and
 * run `compensation:monthly-close --restart` again (ADR-0016, D4).
 *
 * Refused once the month is frozen (finance approved its payout — D9, no
 * override), once the NEXT month has credited anything on its ranks, wallet
 * verdicts and Fortune roster (D12/DN-5), once any of its credits was paid by a
 * frozen batch, or once an admin has reversed one of them.
 *
 * An unapproved payout batch for the month is un-built first, because it is what
 * stamped the credits this wipe removes; rebuilding the batch afterwards is a
 * separate decision (`compensation:rebuild-payout`), which is why the command
 * says so in its warnings rather than doing it.
 *
 * Developer-only, `--actor` required from a shell.
 */
final class RebuildMonthCommand extends Command
{
    use RebuildsPeriod;

    protected $signature = 'compensation:rebuild-month
                            {--month= : The crediting month to rebuild (YYYY-MM)}
                            {--actor= : REQUIRED from a shell — user id of the developer deciding the rebuild}
                            {--yes : Skip the interactive confirmation (the queued job passes it)}';

    protected $description = 'Wipe a month\'s crediting rows and run the monthly close for it again (developer only)';

    protected function kind(): RebuildKind
    {
        return RebuildKind::Month;
    }
}
