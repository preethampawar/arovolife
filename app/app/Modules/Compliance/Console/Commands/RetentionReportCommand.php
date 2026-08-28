<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Console\Commands;

use App\Modules\Compliance\Services\RetentionPolicy;
use Illuminate\Console\Command;

/**
 * Reports — and optionally purges — data past its retention window (R-54).
 *
 * The eight-year period is published in `terms.md` §15, `privacy.md` and the
 * data-model doc, and until now nothing measured it, let alone enforced it.
 * DPDP §8(7) makes a stated period a ceiling as well as a floor, so holding
 * data indefinitely while telling people it is held for eight years is the
 * discrepancy that matters.
 *
 * **Dry run by default**, like `grievance:purge-expired`. A retention job that
 * deletes on its first accidental invocation is worse than no retention job.
 *
 * Only categories marked purgeable in `RetentionPolicy` are ever deleted. The
 * rest — the audit log, consents, terminated distributors — are reported and
 * left alone, because each needs a decision this command has no business
 * making: deleting audit rows breaks the hash chain, consents are the proof
 * that processing was lawful, and a distributor cascades into ledger and tax
 * records other statutes require. Showing the counts means the question gets
 * asked; making the call here would answer it wrongly and silently.
 */
final class RetentionReportCommand extends Command
{
    protected $signature = 'compliance:retention-report
        {--force : Delete expired rows in the purgeable categories. Without this the command only reports.}';

    protected $description = 'Report data past its published retention window, and optionally purge what is safe to purge';

    public function __construct(private readonly RetentionPolicy $policy)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $rows = $this->policy->report();
        $force = (bool) $this->option('force');

        $this->table(
            ['Category', 'Keep for', 'Cutoff', 'Past retention', 'Purgeable here'],
            array_map(static fn (array $row): array => [
                $row['label'],
                $row['years'].' years',
                $row['cutoff'],
                number_format($row['expired']),
                $row['purgeable'] ? 'yes' : 'no — needs a decision',
            ], $rows),
        );

        $needsDecision = array_filter($rows, static fn (array $row): bool => ! $row['purgeable'] && $row['expired'] > 0);

        foreach ($needsDecision as $row) {
            $this->warn("{$row['label']}: {$row['expired']} row(s) past retention and not purgeable here.");
            $this->line('  '.$row['note']);
        }

        $purgeable = array_filter($rows, static fn (array $row): bool => $row['purgeable'] && $row['expired'] > 0);

        if ($purgeable === []) {
            $this->info('Nothing to purge.');

            return self::SUCCESS;
        }

        if (! $force) {
            $total = array_sum(array_column($purgeable, 'expired'));
            $this->newLine();
            $this->warn("DRY RUN — {$total} row(s) would be deleted. Re-run with --force to delete them.");

            return self::SUCCESS;
        }

        foreach ($purgeable as $row) {
            $deleted = $this->policy->purge($row['key']);
            $this->info("{$row['label']}: deleted {$deleted} row(s) older than {$row['cutoff']}.");
        }

        return self::SUCCESS;
    }
}
