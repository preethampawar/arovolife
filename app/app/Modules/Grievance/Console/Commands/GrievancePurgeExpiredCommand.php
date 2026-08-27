<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Console\Commands;

use App\Modules\Grievance\Models\Ticket;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes grievance records whose statutory retention has run out.
 *
 * Policy §6.2 promises retention for seven years from final closure, and the
 * DPDP Act's storage-limitation principle means "seven years" is a ceiling as
 * well as a floor — keeping a complainant's bank screenshots for a decade is
 * its own violation.
 *
 * DELIBERATELY NOT SCHEDULED. Registered so it can be run deliberately, but
 * not wired into routes/console.php: the first automated run will not be due
 * until seven years after the first closure, and by then someone should have
 * decided, with counsel, whether an anonymised summary row should survive the
 * purge for the compliance report's historical counts. Wiring it up now would
 * hard-code that decision years before anyone makes it.
 *
 * Always dry-run unless --force is passed.
 */
final class GrievancePurgeExpiredCommand extends Command
{
    protected $signature = 'grievance:purge-expired
        {--force : Actually delete. Without this the command only reports.}';

    protected $description = 'Delete grievance records whose 7-year retention window (policy §6.2) has expired';

    public function handle(): int
    {
        $today = Carbon::now()->startOfDay();

        $expired = Ticket::whereNotNull('retention_until')
            ->whereDate('retention_until', '<=', $today)
            ->with('attachments')
            ->get();

        if ($expired->isEmpty()) {
            $this->info('No grievance records are past their retention window.');

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            '%d grievance record(s) are past the %d-year retention window.',
            $expired->count(),
            7
        ));

        foreach ($expired as $ticket) {
            $this->line(sprintf(
                '  %s — closed %s, retention ended %s, %d attachment(s)',
                $ticket->ticket_no,
                $ticket->closed_at?->toDateString() ?? 'unknown',
                $ticket->retention_until?->toDateString() ?? 'unknown',
                $ticket->attachments->count(),
            ));
        }

        if (! $this->option('force')) {
            $this->comment('Dry run. Re-run with --force to delete.');

            return self::SUCCESS;
        }

        foreach ($expired as $ticket) {
            foreach ($ticket->attachments as $attachment) {
                Storage::disk($attachment->disk)->delete($attachment->path);
            }

            // ticket_events and ticket_attachments cascade on the FK.
            $ticket->delete();
        }

        $this->info($expired->count().' grievance record(s) purged.');

        return self::SUCCESS;
    }
}
