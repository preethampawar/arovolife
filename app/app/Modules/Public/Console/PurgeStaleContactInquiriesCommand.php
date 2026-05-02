<?php

declare(strict_types=1);

namespace App\Modules\Public\Console;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Public\Models\ContactInquiry;
use Illuminate\Console\Command;

/**
 * Risk register R-15 / DPDP Act 2023 §8(3) — bound retention of personal
 * data captured by the public contact form.
 *
 * Default retention: 90 days for unhandled inquiries (no admin took action),
 * 12 months for handled inquiries (the support trail itself has business
 * value but is still PII so it ages out). Each purge writes one audit log
 * row recording the count and threshold, never the row contents.
 */
final class PurgeStaleContactInquiriesCommand extends Command
{
    protected $signature = 'contact-inquiries:purge
        {--unhandled-days=90 : Delete unhandled inquiries older than this many days}
        {--handled-days=365 : Delete handled inquiries older than this many days}';

    protected $description = 'Purge stale contact_inquiries rows (DPDP §8(3) retention).';

    public function handle(): int
    {
        $unhandledDays = (int) $this->option('unhandled-days');
        $handledDays = (int) $this->option('handled-days');

        $unhandledThreshold = now()->subDays($unhandledDays);
        $handledThreshold = now()->subDays($handledDays);

        $unhandledDeleted = ContactInquiry::query()
            ->whereNull('handled_at')
            ->where('created_at', '<', $unhandledThreshold)
            ->delete();

        $handledDeleted = ContactInquiry::query()
            ->whereNotNull('handled_at')
            ->where('handled_at', '<', $handledThreshold)
            ->delete();

        // Audit log — counts only, never row contents (which contain PII).
        AuditLog::create([
            'actor_id' => null,
            'action' => 'contact_inquiry.retention_purge',
            'subject_type' => 'contact_inquiry',
            'subject_id' => null,
            'details' => [
                'unhandled_deleted' => $unhandledDeleted,
                'handled_deleted' => $handledDeleted,
                'unhandled_days' => $unhandledDays,
                'handled_days' => $handledDays,
            ],
        ]);

        $this->info("Purged {$unhandledDeleted} unhandled (>{$unhandledDays}d) + {$handledDeleted} handled (>{$handledDays}d) inquiries.");

        return self::SUCCESS;
    }
}
