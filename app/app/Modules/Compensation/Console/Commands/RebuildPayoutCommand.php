<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Console\Commands\Concerns\RebuildsPeriod;
use App\Modules\Compensation\Services\Rebuild\RebuildKind;
use Illuminate\Console\Command;

/**
 * Rebuild one crediting month's payout batch: un-build it and run
 * `compensation:monthly-payout-close` again so every distributor's amount is
 * frozen afresh (ADR-0016, D4).
 *
 * `--month` is the CREDITING month, not the month the money moves in: the batch
 * it rebuilds is dated the 1st of the month after it, which is where the monthly
 * sweep puts it.
 *
 * "Re-freeze the per-distributor amounts" is exactly what the re-run does: the
 * batch row, one line item per distributor with its gross, repurchase deduction,
 * admin charge, TDS and net, the sweep stamps on the credits, and the three
 * sweep debits. The credits themselves are un-swept and never deleted (D10), and
 * an approved batch is refused outright (DN-2).
 *
 * Developer-only, `--actor` required from a shell.
 */
final class RebuildPayoutCommand extends Command
{
    use RebuildsPeriod;

    protected $signature = 'compensation:rebuild-payout
                            {--month= : The CREDITING month whose payout batch to rebuild (YYYY-MM)}
                            {--actor= : REQUIRED from a shell — user id of the developer deciding the rebuild}
                            {--yes : Skip the interactive confirmation (the queued job passes it)}';

    protected $description = 'Un-build an unapproved monthly payout batch and build it again (developer only)';

    protected function kind(): RebuildKind
    {
        return RebuildKind::Payout;
    }
}
