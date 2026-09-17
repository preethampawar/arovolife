<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Identity\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Reopen a payout batch a hard kill stranded in `processing`.
 *
 * The one recovery the platform could not perform. `failed` has always been
 * recoverable — `gsb:weekly-payout --date=<Tuesday>` re-enters the batch and
 * skips every distributor already paid — but a sweep killed outright never
 * reaches the catch block that writes `failed`, so the row keeps saying
 * `processing` and `processing` is closed to re-entry. Until now the only way
 * out was an UPDATE typed against the production database by hand.
 *
 * CLI-only, and not on the Engine Runs page, for the same reason the payout
 * engines are not: a button that reopens a payout batch is a button that
 * decides money is owed again. This decision needs someone who has looked at
 * the worker logs and knows the sweep is dead, not someone who saw a red banner.
 * {@see PayoutService::reopenStuckBatch()} refuses on its own if they are wrong.
 */
final class PayoutReopenStuckBatchCommand extends Command
{
    protected $signature = 'payout:reopen-stuck-batch
        {--type=weekly : weekly or monthly}
        {--date= : the batch date (YYYY-MM-DD); for a monthly batch, the 1st of its month}
        {--actor= : REQUIRED — user id of whoever decided the batch is owed again; recorded as its maker}';

    protected $description = 'Reopen a payout batch left stranded in `processing` by a killed sweep, so it can be re-run';

    public function handle(PayoutService $payouts, EngineRunContext $runContext): int
    {
        $type = (string) $this->option('type');

        if (! in_array($type, [PayoutBatch::TYPE_WEEKLY, PayoutBatch::TYPE_MONTHLY], true)) {
            $this->error('--type must be weekly or monthly.');

            return self::FAILURE;
        }

        $rawDate = (string) ($this->option('date') ?? '');

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
            $this->error('--date is required, in YYYY-MM-DD format.');

            return self::FAILURE;
        }

        $date = Carbon::createFromFormat('Y-m-d', $rawDate)->startOfDay();

        $batch = PayoutBatch::whereDate('batch_date', $date->toDateString())
            ->where('batch_type', $type)
            ->first();

        if ($batch === null) {
            $this->error("No {$type} batch dated {$date->toDateString()}.");

            return self::FAILURE;
        }

        // "Last sign of life", not the batch row's `updated_at`: that column is
        // written twice per sweep and dates its START, so printing it here would
        // show the operator the one number this whole command exists to stop
        // them trusting.
        $lastSignOfLife = $payouts->batchLastSignOfLife($batch);

        $this->table(
            ['Batch', 'Type', 'Date', 'Status', 'Lines', 'Batch row written', 'Last sign of life'],
            [[
                $batch->id,
                $batch->batch_type,
                $batch->batch_date->toDateString(),
                $batch->status,
                $batch->lineItems()->count(),
                $batch->updated_at?->toDateTimeString() ?? '—',
                $lastSignOfLife?->toDateTimeString() ?? '—',
            ]],
        );

        // FAIL CLOSED ON THE MAKER. The re-run that follows is a separate
        // process with no session and no attributed run context, so
        // `PayoutService::batchCreatorId()` returns NULL there — a reopened
        // scheduler batch would come back with no maker and be approvable by
        // whoever reopened it, which is the hole R-81 closed. Whoever decides a
        // batch is owed again IS its maker, and `reopenStuckBatch()` stamps
        // them; so there has to be one.
        $actorId = $this->option('actor') !== null
            ? (int) $this->option('actor')
            : $runContext->actorId();

        if ($actorId === null) {
            $this->error('--actor=<user id> is required: a reopened batch must record who decided it is owed again, '
                .'or nothing stops that person from also approving it.');

            return self::FAILURE;
        }

        $actor = User::find($actorId);

        if ($actor === null) {
            $this->error("No user with id {$actorId}.");

            return self::FAILURE;
        }

        // The actor becomes `created_by`, so they must be someone who could have
        // made this batch in the first place. A maker who holds no payout
        // permission is a false maker, and a false maker is worse than none: the
        // approval bar would be pointing at somebody who was never going to
        // approve anything.
        if (! $actor->can('finance.record')) {
            $this->error("User {$actorId} does not hold `finance.record`, so they cannot be recorded as the "
                .'maker of a payout batch. Name whoever is accountable for re-running it.');

            return self::FAILURE;
        }

        if ($batch->created_by !== null && $batch->created_by !== $actorId) {
            $this->warn(sprintf(
                'This batch already records user %d as its maker; that is who stays barred from approving it. '
                .'You are recorded in the audit trail either way.',
                $batch->created_by,
            ));
        }

        if (! $this->confirm('Reopen this batch as `failed` so it can be re-run?', false)) {
            $this->info('Left as it is.');

            return self::SUCCESS;
        }

        $refusal = $payouts->reopenStuckBatch($batch, $actorId);

        if ($refusal !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        $this->info("Batch {$batch->id} is now `failed`. Re-run it with:");
        $this->line($type === PayoutBatch::TYPE_WEEKLY
            ? "  php artisan gsb:weekly-payout --date={$batch->batch_date->toDateString()}"
            : "  php artisan compensation:monthly-payout-close --month={$batch->batch_date->format('Y-m')}");

        return self::SUCCESS;
    }
}
