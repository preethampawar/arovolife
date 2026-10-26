<?php

declare(strict_types=1);

namespace App\Modules\Consent\Console\Commands;

use App\Modules\Consent\Services\PlanChangeNotices;
use Illuminate\Console\Command;

/**
 * Emails the compensation-plan change notice to every distributor who has not
 * agreed to the published text three days after the notice started (plan page
 * §12, R-123), and prints the evidence counts and the earliest lawful start
 * date. Idempotent: each distributor is emailed once per plan text.
 */
final class NotifyUnreadPlanChangesCommand extends Command
{
    protected $signature = 'plan:notify-unread';

    protected $description = 'Email the plan-change notice to distributors who have not agreed to the published plan within 3 days';

    public function handle(PlanChangeNotices $notices): int
    {
        $summary = $notices->notifyUnread();

        if ($summary->blockedReason !== null) {
            $this->error('Plan change notice held: '.$summary->blockedReason);

            return self::FAILURE;
        }

        if ($summary->noticeStartedAt === null) {
            $this->info('Plan change notice is off, or the plan page is not published — nothing to do.');

            return self::SUCCESS;
        }

        $format = fn ($date): string => $date?->timezone('Asia/Kolkata')->format('d M Y H:i').' IST';

        $this->table(['Item', 'Value'], [
            ['Plan version', $summary->label ?? '(no label in the page footer)'],
            ['Notice started', $format($summary->noticeStartedAt)],
            ['Emails due from', $format($summary->emailsDueFrom)],
            ['Agreed', (string) $summary->accepted],
            ['Not agreed yet', (string) $summary->notAccepted],
            ['Emailed this run', (string) $summary->emailedNow],
            ['Emailed in total', (string) $summary->emailedEver],
            ['Email failed this run', (string) count($summary->failedAdns)],
            ['Skipped (no user account)', (string) $summary->skippedNoUser],
            ['Reserved company accounts not agreed (not emailed)', (string) $summary->reservedNotAccepted],
            ['Never reached (email failed, not agreed)', (string) $summary->neverReached],
            ['Last notice email', $summary->lastEmailedAt === null ? '—' : $format($summary->lastEmailedAt)],
            ['Earliest start (reached distributors only)', $summary->earliestStart()?->format('d M Y') ?? '—'],
        ]);

        if ($summary->neverReached > 0) {
            $this->warn('Do not write a start date on the plan page while "Never reached" is above 0, unless R-123 covers each one.');
        }

        if ($summary->failedAdns !== []) {
            $this->warn('Email failed for ADN(s): '.implode(', ', $summary->failedAdns).' — retried on the next run.');
        }

        return self::SUCCESS;
    }
}
