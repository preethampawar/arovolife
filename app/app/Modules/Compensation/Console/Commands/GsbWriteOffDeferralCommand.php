<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Models\GsbCutoffDeferral;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Close an owed GSB cut-off day by decision, with no credit (E5 review L3).
 *
 * The nightly backfill never settles a distributor who is no longer active, so
 * their deferral row would stay open — and in the digest — for ever. Writing
 * the day off is a decision about money someone was owed, so it is never
 * automatic: a human runs this with a reason, and the audit log keeps who,
 * when, why and what had been reserved. Nothing is credited or reversed; the
 * day's frozen pools are untouched, so the reservation simply stays in the
 * day's leftover.
 */
final class GsbWriteOffDeferralCommand extends Command
{
    protected $signature = 'gsb:write-off-deferral
        {distributor : distributor id or ADN}
        {date : the owed cut-off day (YYYY-MM-DD)}
        {--reason= : REQUIRED — why the owed day is written off; recorded in the audit log}';

    protected $description = 'Close an open deferred GSB cut-off day by decision, with no credit, and audit it';

    public function handle(EngineRunContext $runContext): int
    {
        $reason = trim((string) ($this->option('reason') ?? ''));

        if ($reason === '') {
            $this->error('--reason is required: writing off an owed day is a decision about money, and the audit log must say why.');

            return self::FAILURE;
        }

        $rawDate = (string) $this->argument('date');

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
            $this->error("date must be in YYYY-MM-DD format, got: {$rawDate}");

            return self::FAILURE;
        }

        $key = (string) $this->argument('distributor');
        $distributor = Distributor::where('adn', $key)->first()
            ?? (ctype_digit($key) ? Distributor::find((int) $key) : null);

        if ($distributor === null) {
            $this->error("No distributor with id or ADN {$key}.");

            return self::FAILURE;
        }

        $date = Carbon::createFromFormat('Y-m-d', $rawDate)->startOfDay();

        $deferral = GsbCutoffDeferral::where('distributor_id', $distributor->id)
            ->whereDate('cutoff_date', $date->toDateString())
            ->first();

        if ($deferral === null) {
            $this->error("ADN {$distributor->adn} has no deferred cut-off for {$date->toDateString()}.");

            return self::FAILURE;
        }

        if ($deferral->resolved_at !== null) {
            $this->error(sprintf(
                'The deferred cut-off for ADN %s on %s is already resolved (%s on %s); nothing to write off.',
                $distributor->adn,
                $date->toDateString(),
                (string) $deferral->resolution,
                $deferral->resolved_at->toDateTimeString(),
            ));

            return self::FAILURE;
        }

        $deferral->update([
            'resolved_at' => Carbon::now(),
            'resolution' => GsbCutoffDeferral::RESOLUTION_WRITTEN_OFF,
        ]);

        $details = [
            'distributor_id' => $distributor->id,
            'adn' => $distributor->adn,
            'cutoff_date' => $date->toDateString(),
            'reason' => $reason,
            'reserved_slab' => $deferral->reserved_slab,
            'reserved_gsb_paise' => $deferral->reserved_gsb_paise,
            'reserved_msb_points' => $deferral->reserved_msb_points,
        ];

        AuditLog::create([
            'actor_id' => $runContext->actorId(),
            'action' => 'gsb.cutoff.deferral_written_off',
            'subject_type' => 'gsb_cutoff_deferral',
            'subject_id' => $deferral->id,
            'before_hash' => null,
            'after_hash' => null,
            'details' => $details,
        ]);

        // The free-text reason stays in the audit row, out of the log.
        Log::warning('gsb.cutoff.deferral_written_off', array_diff_key($details, ['reason' => true]));

        $this->info("Wrote off the deferred cut-off for ADN {$distributor->adn} on {$date->toDateString()}. Nothing was credited.");

        return self::SUCCESS;
    }
}
