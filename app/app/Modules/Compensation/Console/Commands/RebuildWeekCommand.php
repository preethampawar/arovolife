<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Console\Commands\Concerns\RebuildsPeriod;
use App\Modules\Compensation\Services\Rebuild\RebuildKind;
use Illuminate\Console\Command;

/**
 * Rebuild one Tuesday's weekly payout batch: un-build it and let
 * `gsb:weekly-payout` build it again (ADR-0016, D4).
 *
 * The credits it swept are UN-SWEPT, never deleted — earned commission is
 * append-only (D10). What goes is the batch's own projection of a payment that
 * never happened: its line items, its `payout_debit` / `admin_charge_debit` /
 * `tds_debit` / `income_cap_forfeit` rows and the batch row itself (DN-1).
 *
 * Refused the moment finance has signed the batch off, or it has reached the
 * payment gateway (DN-2): an approved batch is a payment instruction that left
 * the company, and its remedy is a per-line retry on the Payouts page.
 *
 * The rebuilt batch records the developer as its maker, so a second person must
 * approve it (R-81, D14).
 */
final class RebuildWeekCommand extends Command
{
    use RebuildsPeriod;

    protected $signature = 'compensation:rebuild-week
                            {--date= : The Tuesday the batch is dated (YYYY-MM-DD)}
                            {--actor= : REQUIRED from a shell — user id of the developer deciding the rebuild}
                            {--yes : Skip the interactive confirmation (the queued job passes it)}';

    protected $description = 'Un-build an unapproved weekly payout batch and build it again (developer only)';

    protected function kind(): RebuildKind
    {
        return RebuildKind::Week;
    }
}
