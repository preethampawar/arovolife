<?php

declare(strict_types=1);

namespace App\Modules\Shared\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as MonologLogger;

/**
 * Laravel "tap" entry point. Configured channels in config/logging.php
 * reference this class via `'tap' => [TapPiiScrubber::class]`. Laravel
 * instantiates this and calls __invoke($logger), giving us the underlying
 * Monolog logger; we push the PII scrubber processor onto it.
 *
 * Toggleable with `LOG_PII_SCRUB=false` for dev debugging that needs raw
 * payloads — never set this to false in any prod-like environment.
 */
final class TapPiiScrubber
{
    public function __invoke(Logger $logger): void
    {
        if (! filter_var(config('logging.pii_scrub', true), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $monolog = $logger->getLogger();
        if ($monolog instanceof MonologLogger) {
            $monolog->pushProcessor(new PiiScrubberProcessor);
        }
    }
}
