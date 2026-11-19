<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Console\Commands;

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Console\Command;
use SplQueue;

/**
 * Walks the audit-log hash chain and reports the first break (T-6.1 M-1).
 *
 * Two checks per row. Link: its prev_hash must be the hash of a row seen
 * earlier, so a deleted row is caught. Content: it must re-hash to its own
 * row_hash. A row whose prev_hash is not its immediate neighbour is not a
 * break: two writers that insert at the same moment read the same latest hash,
 * so they legitimately share a predecessor. Rows sealed by the first hashing
 * method (hash_version NULL) had their details reordered by MySQL, so only
 * their link is checked.
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

    /** How many recent row hashes a row's predecessor is looked up in. */
    private const LINK_WINDOW = 20000;

    public function handle(): int
    {
        $previousHash = null;
        $head = null;
        $checked = 0;
        $skipped = 0;
        $concurrent = 0;
        $legacyUnverifiable = 0;
        $broken = null;
        $reason = '';
        $sawCurrentVersion = false;

        $window = [];
        $order = new SplQueue;

        AuditLog::query()
            ->orderBy('id')
            ->chunk(self::CHUNK, function ($entries) use (&$previousHash, &$head, &$checked, &$skipped, &$concurrent, &$legacyUnverifiable, &$broken, &$reason, &$window, &$sawCurrentVersion, $order): bool {
                foreach ($entries as $entry) {
                    // Rows written before the chain existed carry no hash.
                    // Counted and skipped rather than failed: back-filling them
                    // would be attesting to history nobody witnessed.
                    if ($entry->row_hash === null) {
                        $skipped++;

                        continue;
                    }

                    $rowHash = (string) $entry->row_hash;

                    // The first hashed row is left unconstrained: once old
                    // months are removed its predecessor is gone too.
                    if ($previousHash !== null) {
                        $prev = $entry->prev_hash === null ? null : (string) $entry->prev_hash;

                        if ($prev === null || ! isset($window[$prev])) {
                            $broken = $entry;
                            $reason = 'its link to an earlier row is missing';

                            return false;
                        }

                        if (! hash_equals($previousHash, $prev)) {
                            $concurrent++;
                        }
                    }

                    // Legacy leniency covers only the unbroken run of first-method
                    // rows at the start of the chain. Once a version-2 row has
                    // been seen, a later row without that version is an edited
                    // row passed off as legacy, not an old one.
                    $isCurrent = $entry->hash_version === AuditLog::HASH_VERSION;

                    if (! $isCurrent && ($entry->hash_version !== null || $sawCurrentVersion)) {
                        $broken = $entry;
                        $reason = $entry->hash_version !== null
                            ? 'sealed by an unknown hash version'
                            : 'sealed by the old method after the new method began';

                        return false;
                    }

                    // Against the row's own prev_hash, not the row before it:
                    // a concurrent writer's predecessor is not its neighbour.
                    $matches = hash_equals(
                        AuditLog::computeRowHash($entry, $entry->prev_hash === null ? null : (string) $entry->prev_hash),
                        $rowHash,
                    );

                    if ($isCurrent) {
                        $sawCurrentVersion = true;

                        if (! $matches) {
                            $broken = $entry;
                            $reason = 'content does not match its hash';

                            return false;
                        }
                        $checked++;
                    } elseif ($matches) {
                        $checked++;
                    } else {
                        $legacyUnverifiable++;
                    }

                    $window[$rowHash] = true;
                    $order->enqueue($rowHash);
                    if ($order->count() > self::LINK_WINDOW) {
                        unset($window[$order->dequeue()]);
                    }

                    $previousHash = $rowHash;
                    $head = $rowHash;
                }

                return true;
            });

        if ($broken !== null) {
            $this->error("AUDIT LOG CHAIN BROKEN at id {$broken->id}.");
            $this->line("  action:  {$broken->action}");
            $this->line('  at:      '.$broken->created_at->toDateTimeString());
            $this->line("  reason:  {$reason}");
            $this->line("  verified rows before the break: {$checked}");
            $this->newLine();
            $this->line('This means a row at or before this point was edited or deleted after it');
            $this->line('was written. Treat it as an incident, not a bug: preserve the database,');
            $this->line('and check who has had write access since the last clean verification.');

            return self::FAILURE;
        }

        $head = $head === null ? '(empty chain)' : bin2hex($head);

        $this->info("Audit log intact — {$checked} rows verified.");

        if ($legacyUnverifiable > 0) {
            $this->line("{$legacyUnverifiable} earlier row(s) were sealed by the first hashing method: their place in the chain was checked, their content cannot be.");
        }

        if ($concurrent > 0) {
            $this->line("{$concurrent} row(s) were written at the same moment as another and share a predecessor.");
        }

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
