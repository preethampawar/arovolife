<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Console\Commands;

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Console\Command;

/**
 * Walks the audit-log hash chain and reports the first break (T-6.1 M-1).
 *
 * Run it before any compliance review, and after any database restore. A chain
 * that is never verified is a chain nobody would notice was broken, which is
 * the same as not having one.
 *
 * The head hash it prints is the thing worth keeping. Copy it somewhere the
 * application cannot write — a ticket, an email to the Compliance Officer, a
 * printed sheet. Anyone who can run this codebase can rewrite the chain from
 * the point they tamper; what they cannot do is change a hash you already wrote
 * down elsewhere.
 */
final class VerifyAuditLogCommand extends Command
{
    protected $signature = 'compliance:verify-audit-log
        {--head= : Expected head hash (hex) from your last verification}';

    protected $description = 'Verify the audit-log hash chain and print its head';

    private const CHUNK = 1000;

    public function handle(): int
    {
        $expectedPrevious = null;
        $checked = 0;
        $skipped = 0;
        $broken = null;

        AuditLog::query()
            ->orderBy('id')
            ->chunk(self::CHUNK, function ($entries) use (&$expectedPrevious, &$checked, &$skipped, &$broken): bool {
                foreach ($entries as $entry) {
                    // Rows written before the chain existed carry no hash.
                    // Counted and skipped rather than failed: back-filling them
                    // would be attesting to history nobody witnessed.
                    if ($entry->row_hash === null) {
                        $skipped++;

                        continue;
                    }

                    $expected = AuditLog::computeRowHash($entry, $expectedPrevious);

                    if (! hash_equals($expected, (string) $entry->row_hash)) {
                        $broken = $entry;

                        return false;
                    }

                    // The link to the row before it must also match, or a row
                    // could be removed wholesale and the rest still verify
                    // against each other.
                    if ($expectedPrevious !== null && ! hash_equals($expectedPrevious, (string) $entry->prev_hash)) {
                        $broken = $entry;

                        return false;
                    }

                    $expectedPrevious = (string) $entry->row_hash;
                    $checked++;
                }

                return true;
            });

        if ($broken !== null) {
            $this->error("AUDIT LOG CHAIN BROKEN at id {$broken->id}.");
            $this->line("  action:  {$broken->action}");
            $this->line('  at:      '.$broken->created_at->toDateTimeString());
            $this->line("  verified rows before the break: {$checked}");
            $this->newLine();
            $this->line('This means a row at or before this point was edited or deleted after it');
            $this->line('was written. Treat it as an incident, not a bug: preserve the database,');
            $this->line('and check who has had write access since the last clean verification.');

            return self::FAILURE;
        }

        $head = $expectedPrevious === null ? '(empty chain)' : bin2hex($expectedPrevious);

        $this->info("Audit log intact — {$checked} rows verified.");

        if ($skipped > 0) {
            $this->line("{$skipped} row(s) predate the hash chain and were skipped.");
        }

        $this->line("Head: {$head}");

        $claimed = (string) ($this->option('head') ?? '');

        if ($claimed !== '') {
            if (! hash_equals($head, $claimed)) {
                $this->error('The head does not match the one you supplied.');
                $this->line('The chain verifies internally, so it was rewritten wholesale —');
                $this->line('which is what tampering by someone who can run this code looks like.');

                return self::FAILURE;
            }

            $this->info('Head matches the value supplied.');
        }

        return self::SUCCESS;
    }
}
