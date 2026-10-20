<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Services\Exceptions\JoiningDateChangeRefused;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;

/**
 * Developer-only correction of a distributor's joining date
 * (`distributors.effective_date`). Every reader of that column follows the
 * change — Fortune Bonus new-joiner tier, line-change window, inactivity
 * clock, team stats — so the guards below refuse any move that would
 * contradict data already derived from the old date:
 *
 *  - not in the future;
 *  - not a root account — the earliest joining date is PlatformStart, which
 *    decides which months every engine owes a cut-off for;
 *  - strictly after the sponsor's and placement parent's joining dates, and
 *    strictly before every direct sponsee's / placement child's — the
 *    ordering RequestLineChange relies on;
 *  - not before the terms acceptance or orientation pass (hard rule 4);
 *  - not into or out of a month Fortune Bonus has already run, whose
 *    new-joiner tier was counted by joining month.
 *
 * Cooling-off (hard rule 5) is never shortened: the end date becomes the
 * later of the current end date and new joining date + 30 days. When it is
 * extended, the D-7 / D-1 reminder flags are cleared so they fire again.
 *
 * A couple moves as one — the spouse row carries the primary's dates.
 */
final class ChangeDistributorJoiningDate
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function __invoke(int $distributorId, Carbon $newDate, string $reason, int $actorId, ?string $ip): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new JoiningDateChangeRefused('A reason is required.');
        }

        $this->db->connection()->transaction(function () use ($distributorId, $newDate, $reason, $actorId, $ip): void {
            /** @var Distributor $distributor */
            $distributor = Distributor::query()->lockForUpdate()->findOrFail($distributorId);

            if ($distributor->spouse_distributor_id !== null && ! $distributor->is_primary_couple) {
                /** @var Distributor $distributor */
                $distributor = Distributor::query()->lockForUpdate()->findOrFail($distributor->spouse_distributor_id);
            }

            $coupleIds = array_values(array_filter([(int) $distributor->id, $distributor->spouse_distributor_id !== null ? (int) $distributor->spouse_distributor_id : null]));

            $old = $distributor->effective_date->copy();
            // Keep the original time of day so same-day ordering against
            // the rest of the tree is unchanged.
            $new = $newDate->copy()->setTimeFrom($old);

            if ($new->isSameDay($old)) {
                throw new JoiningDateChangeRefused('The new joining date is the same as the current one.');
            }
            if ($new->isFuture()) {
                throw new JoiningDateChangeRefused('The joining date cannot be in the future.');
            }
            if ((int) $distributor->sponsor_id === (int) $distributor->id
                || (int) $distributor->placement_parent_id === (int) $distributor->id) {
                throw new JoiningDateChangeRefused('A root account\'s joining date sets the platform start date and cannot be changed here.');
            }

            $upline = $this->db->table('distributors')
                ->whereIn('id', [$distributor->sponsor_id, $distributor->placement_parent_id])
                ->max('effective_date');
            if ($upline !== null && ! $new->greaterThan(Carbon::parse($upline))) {
                throw new JoiningDateChangeRefused('The joining date must be after the sponsor\'s and placement parent\'s joining date ('.Carbon::parse($upline)->format('d M Y').').');
            }

            $downline = $this->db->table('distributors')
                ->where(fn ($query) => $query->whereIn('sponsor_id', $coupleIds)->orWhereIn('placement_parent_id', $coupleIds))
                ->whereNotIn('id', $coupleIds)
                ->min('effective_date');
            if ($downline !== null && ! $new->lessThan(Carbon::parse($downline))) {
                throw new JoiningDateChangeRefused('The joining date must be before the earliest joining date in this distributor\'s direct team ('.Carbon::parse($downline)->format('d M Y').').');
            }

            // The agreement cannot take effect before the terms were accepted
            // or orientation was passed (hard rule 4) — those are historical
            // facts this correction must not contradict.
            $acceptedAt = collect([
                $this->db->table('consents')->whereIn('distributor_id', $coupleIds)->max('accepted_at'),
                $this->db->table('orientation_views')->whereIn('distributor_id', $coupleIds)->max('quiz_passed_at'),
            ])->filter()->map(fn ($at): Carbon => Carbon::parse($at))->max();
            if ($acceptedAt !== null && $new->lessThan($acceptedAt)) {
                throw new JoiningDateChangeRefused('The joining date cannot be earlier than the terms acceptance or orientation pass ('.$acceptedAt->format('d M Y, h:i A').').');
            }

            $fortuneBonusRan = $this->db->table('fortune_bonus_participants')
                ->whereIn('month_start', [$old->copy()->startOfMonth()->toDateString(), $new->copy()->startOfMonth()->toDateString()])
                ->exists();
            if ($fortuneBonusRan) {
                throw new JoiningDateChangeRefused('Fortune Bonus has already run for '.$old->format('M Y').' or '.$new->format('M Y').'; its new-joiner tier was counted on the current date.');
            }

            $coolingOffEnd = $distributor->cooling_off_end_at->max($new->copy()->addDays(30));

            $before = [
                'effective_date' => $old->toIso8601String(),
                'cooling_off_end_at' => $distributor->cooling_off_end_at->toIso8601String(),
            ];
            $after = [
                'effective_date' => $new->toIso8601String(),
                'cooling_off_end_at' => $coolingOffEnd->toIso8601String(),
            ];

            $this->db->table('distributors')->whereIn('id', $coupleIds)->update([
                'effective_date' => $new->format('Y-m-d H:i:s.v'),
                'cooling_off_end_at' => $coolingOffEnd->format('Y-m-d H:i:s.v'),
                'updated_at' => now(),
            ]);

            // An extended window owes fresh D-7 / D-1 reminders for the new
            // end date (hard rule 5); the sweep only sends while these are null.
            if ($coolingOffEnd->greaterThan($distributor->cooling_off_end_at)) {
                $this->db->table('cooling_off_events')
                    ->whereIn('distributor_id', $coupleIds)
                    ->whereNull('cancelled_at')
                    ->update(['reminder_d7_sent_at' => null, 'reminder_d1_sent_at' => null]);
            }

            AuditLog::create([
                'actor_id' => $actorId,
                'action' => 'admin.distributor.joining_date_changed',
                'subject_type' => 'distributor',
                'subject_id' => $distributor->id,
                'before_hash' => AuditDigests::of($before),
                'after_hash' => AuditDigests::of($after),
                'details' => [
                    'before' => $before,
                    'after' => $after,
                    'distributor_ids' => $coupleIds,
                    'reason' => $reason,
                ],
                'ip' => $ip,
            ]);
        });
    }
}
