<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Console\Commands;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Content\Models\AnnouncementRead;
use App\Modules\Messaging\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * DPDP Act 2023 §4 / §8(3) — bound retention for the communications tables.
 *
 * Privacy notice §5 promises three periods, and a promise with nothing to
 * enforce it is how `contact_inquiries` earned R-15. This is the enforcement:
 *
 *   - in-app messages: 24 months from the date sent;
 *   - announcement read receipts: 24 months;
 *   - block list entries: never aged out here — a block ends when the person
 *     who set it removes it, which deletes the row, and a block that is still
 *     in place is still being relied on.
 *
 * A message that has been reported is held back regardless of age. The report
 * is the moderation record (7 years, DSR 2021 Rule 12) and `message_reports`
 * cascades on `messages`, so purging the message would destroy the evidence
 * the report exists to preserve — including an open one. Grievance evidence is
 * held the same way once messages can be attached to a grievance.
 *
 * The audit row records counts and thresholds only, never row contents.
 */
final class PurgeExpiredMessagesCommand extends Command
{
    protected $signature = 'messages:purge
        {--months=24 : Delete messages and announcement reads older than this many months}
        {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Purge expired in-app messages and announcement read receipts (DPDP §4 retention).';

    public function handle(): int
    {
        $months = max(1, (int) $this->option('months'));
        $threshold = now()->subMonths($months);
        $dryRun = (bool) $this->option('dry-run');

        // Reported messages are on legal hold — see the class docblock.
        $expiredMessages = Message::query()
            ->where('created_at', '<', $threshold)
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('message_reports')
                    ->whereColumn('message_reports.message_id', 'messages.id');
            });

        $expiredReads = AnnouncementRead::query()->where('read_at', '<', $threshold);

        if ($dryRun) {
            $this->info(sprintf(
                '%d messages and %d announcement reads are older than %d months (%s). Nothing deleted.',
                $expiredMessages->count(),
                $expiredReads->count(),
                $months,
                $threshold->toDateString(),
            ));

            return self::SUCCESS;
        }

        $messagesDeleted = $expiredMessages->delete();
        $readsDeleted = $expiredReads->delete();

        $heldForReport = Message::query()
            ->where('created_at', '<', $threshold)
            ->count();

        AuditLog::create([
            'actor_id' => null,
            'action' => 'messaging.retention_purge',
            'subject_type' => 'message',
            'subject_id' => null,
            'details' => [
                'messages_deleted' => $messagesDeleted,
                'announcement_reads_deleted' => $readsDeleted,
                'messages_held_for_report' => $heldForReport,
                'months' => $months,
                'threshold' => $threshold->toDateTimeString(),
            ],
        ]);

        $this->info("Purged {$messagesDeleted} messages and {$readsDeleted} announcement reads older than {$months} months; {$heldForReport} held against a report.");

        return self::SUCCESS;
    }
}
