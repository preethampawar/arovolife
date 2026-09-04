<?php

declare(strict_types=1);

namespace App\Modules\Payments\Console\Commands;

use App\Modules\Payments\Models\PaymentEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Retention for the gateway event payloads (DPDP 2023 §8(7)).
 *
 * The derived transactional fields — ids, type, status, amounts, timestamps —
 * stay for the eight-year transactional record. The payload itself, already
 * scrubbed on write, is kept only through the dispute/chargeback window and
 * then dropped: it holds card last-4, network and acquirer references,
 * which are needed to trace a disputed refund and for nothing else.
 */
final class PaymentsRedactEventsCommand extends Command
{
    public const DEFAULT_DAYS = 180;

    protected $signature = 'payments:redact-events
        {--days='.self::DEFAULT_DAYS.' : Redact payloads on events older than this many days}
        {--dry-run : Count only}';

    protected $description = 'Drop the stored gateway payload from payment events older than the dispute window';

    public function handle(): int
    {
        $days = max(30, (int) $this->option('days'));
        $cutoff = Carbon::now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $query = PaymentEvent::query()->whereNotNull('payload')->where('created_at', '<', $cutoff);

        $count = $dryRun ? $query->count() : $query->update(['payload' => null]);

        $this->info(sprintf('%s%d event payload(s) older than %d days redacted.', $dryRun ? '[dry run] ' : '', $count, $days));

        return self::SUCCESS;
    }
}
