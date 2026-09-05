<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use FilesystemIterator;
use Illuminate\Support\Carbon;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Detects a queue worker running code older than the deployed source.
 *
 * A `queue:work` process loads its classes once and keeps them for its whole
 * life. Deploy new code without restarting the worker and every job it picks
 * up afterwards executes the OLD logic — silently, with no error anywhere.
 *
 * For most queues that is a stale email template. On the `compensation` queue
 * it is wrong money: local, 5 Sep 2026, the compensation worker had booted
 * before the repurchase-deduction-at-credit-time change landed, so an
 * admin-triggered Rank Bonus run credited ₹6,30,644.28 with zero repurchase
 * deduction and nothing in the run log said so. Only re-reading the ledger
 * caught it.
 *
 * The signal is the worker's own boot time against the newest mtime under
 * app/: a source file modified AFTER the process started cannot be loaded in
 * it. Both directions are sound — a worker booted after the last edit is
 * definitively fresh — so the check never nags a correctly restarted worker.
 *
 * Scope note: only app/ is watched. A change confined to config/, routes/ or
 * vendor/ will not be seen, so `queue:restart` on deploy remains the actual
 * discipline; this is the backstop that keeps a missed restart from moving
 * money.
 */
final class WorkerFreshness
{
    /**
     * Why the current process is running stale code, or null when it is fresh
     * (or when staleness cannot apply — a web request or a test run, where the
     * process boots per request).
     */
    public static function staleReason(): ?string
    {
        $bootedAt = self::processBootedAt();

        if ($bootedAt === null || ! app()->runningInConsole()) {
            return null;
        }

        $changedAt = self::newestSourceChangeAt();

        if ($changedAt === null || $changedAt->lessThanOrEqualTo($bootedAt)) {
            return null;
        }

        return sprintf(
            'This queue worker booted at %s but app/ was last modified at %s, so it is running pre-deploy code. '
            .'Restart the compensation worker (php artisan queue:restart, or restart the queue containers) and trigger the run again.',
            $bootedAt->toDateTimeString(),
            $changedAt->toDateTimeString(),
        );
    }

    /**
     * When this PHP process started. LARAVEL_START is defined by artisan and
     * by public/index.php, so it is the process boot time for a worker and the
     * request start for a web request; it is undefined under PHPUnit, which is
     * why tests never trip the guard.
     */
    private static function processBootedAt(): ?Carbon
    {
        return defined('LARAVEL_START')
            ? Carbon::createFromTimestamp((float) LARAVEL_START)
            : null;
    }

    /** Newest mtime of any .php file under app/, or null if unreadable. */
    private static function newestSourceChangeAt(): ?Carbon
    {
        $newest = 0;

        try {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $newest = max($newest, $file->getMTime());
                }
            }
        } catch (\Throwable) {
            return null; // Unreadable tree: never block a run over a guard we cannot evaluate.
        }

        return $newest > 0 ? Carbon::createFromTimestamp($newest) : null;
    }
}
