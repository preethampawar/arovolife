<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Exceptions;

use App\Modules\Compensation\Services\Rebuild\RebuildPlanner;
use RuntimeException;

/**
 * The period moved between the preview an operator read and the wipe they
 * confirmed, so the wipe refused rather than acting on state nobody agreed to.
 *
 * The window is small and entirely real: a developer types
 * `compensation:rebuild-night --date=N` at 00:03, reads a clean preview, the
 * 00:05 nightly run cuts day N off while they are reading, and they answer the
 * confirm at 00:07. A preflight that ran at 00:03 refuses nothing. The wipe
 * would then rewind the carry-forward store under a day that has already been
 * built on it, and every retry afterwards is out of order — the day becomes
 * un-provable for good.
 *
 * So {@see RebuildPlanner::execute()} re-plans and compares the fingerprint
 * with the confirmed plan's, inside the rebuilder's own transaction and before
 * its first delete. Anything that changed — a new refusal, or merely a
 * different row count — aborts with this, the transaction rolls back and
 * nothing is written. The rebuild commands turn it into a refusal an operator
 * can read and act on: run it again and read the new preview.
 */
final class RebuildStateChanged extends RuntimeException
{
    /** @param  list<string>  $refusals */
    public static function refused(array $refusals): self
    {
        return new self(
            'The period changed while this rebuild was waiting to be confirmed, so nothing was written. Run the '
            ."rebuild again and read the new preview first. What refuses it now:\n"
            .implode("\n", $refusals),
        );
    }

    public static function changed(): self
    {
        return new self(
            'The period changed while this rebuild was waiting to be confirmed: the rows it would remove are no '
            .'longer the ones the preview described, so nothing was written. Run the rebuild again and read the new '
            .'preview first.',
        );
    }
}
