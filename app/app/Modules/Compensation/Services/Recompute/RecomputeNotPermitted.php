<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\Recompute;

use RuntimeException;

/**
 * Thrown when a full compensation recompute is attempted somewhere it must not
 * run. Carries the operator-facing reason so the command and the controller can
 * report the same words.
 */
final class RecomputeNotPermitted extends RuntimeException
{
    public static function inProduction(): self
    {
        return new self(
            'Refusing to recompute: this is a production environment. '
            .'The compensation engines are write-once in production by design — '
            .'a period already paid can never be re-priced.'
        );
    }

    public static function notEnabled(): self
    {
        return new self(
            'Refusing to recompute: COMP_RECOMPUTE_ENABLED is not set. '
            .'This is a testing-only tool that destroys every BV-derived row; '
            .'enable it deliberately in the target environment first.'
        );
    }

    /** @param  list<string>  $allowed */
    public static function databaseNotAllowed(string $database, array $allowed): self
    {
        return new self(
            "Refusing to recompute: the connected database is '{$database}', which is not "
            .'in COMP_RECOMPUTE_ALLOWED_DATABASES ('
            .($allowed === [] ? 'empty — no database is permitted' : implode(', ', $allowed))
            .'). This tool destroys cooling-off windows, invoices, buyback evidence and the '
            .'TDS trail, so the database itself must be named — an environment label is not '
            .'enough to prove nobody real is in there.'
        );
    }

    public static function alreadyRunning(): self
    {
        return new self(
            'A compensation recompute is already in flight. Wait for it to finish — '
            .'two concurrent replays would corrupt the carry-forward chain.'
        );
    }
}
