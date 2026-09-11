<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The durable record of a pool that was frozen too early and had to be KEPT.
 *
 * The self-heal replaces a prematurely frozen pool while nothing has been
 * funded by it. Once something has, replacing it would change economics money
 * already moved on, so the row stays — and every later run for that period
 * prices against the wrong snapshot. Staging, 05 Sep 2026: a mid-day freeze
 * held the day at 5.59 cr BV against a true 8.09 cr, the MSB point value at
 * ₹430 against ₹622, and one distributor was underpaid ₹7,488.
 *
 * That was visible only as a `premature_freeze_kept` log line: no audit row, no
 * failed run, nothing in the health digest, and nobody looked for a month. A
 * kept freeze is a money-affecting fact, so it gets a retention-guaranteed
 * audit row of its own, which the digest then reports (F30).
 *
 * Deliberately independent of `engine_runs`: the run that discovers the kept
 * freeze succeeds — it does everything else correctly — so there is no failed
 * row for the signal to hang off.
 */
final class PrematureFreezeAlert
{
    public const ACTION = 'compensation.premature_freeze_kept';

    /**
     * One row per kept pool, not one per run that notices it: the condition is
     * permanent, and every subsequent run for the period re-detects it.
     *
     * @param  string  $engineKey  The registry key of the engine that owns the pool.
     * @param  string  $period  The period as the engine formats it (YYYY-MM-DD or YYYY-MM).
     * @param  array<string, mixed>  $details  The frozen figures, for the audit record.
     */
    public static function kept(
        string $engineKey,
        string $subjectType,
        int $subjectId,
        string $period,
        string $reason,
        array $details,
    ): void {
        try {
            $alreadyRecorded = AuditLog::query()
                ->where('action', self::ACTION)
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->exists();

            if ($alreadyRecorded) {
                return;
            }

            AuditLog::create([
                'actor_id' => null,
                'action' => self::ACTION,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'details' => $details + [
                    'engine_key' => $engineKey,
                    'period' => $period,
                    'reason' => $reason,
                    'detected_at' => Carbon::now()->toDateTimeString(),
                ],
            ]);
        } catch (Throwable) {
            // The log line the caller already wrote is the fallback. Losing the
            // audit row must never abort the run that found the problem — it is
            // still crediting everybody else correctly.
        }
    }
}
