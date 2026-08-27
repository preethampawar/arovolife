<?php

declare(strict_types=1);

namespace App\Modules\Shared\Security;

use Illuminate\Http\UploadedFile;

/**
 * The scanner used when none is configured.
 *
 * Passes in local and testing so development and the suite are not blocked on
 * a daemon nobody is running, and **refuses everywhere else**. The alternative
 * — passing silently in production — would let the platform report that
 * uploads are scanned when nothing is scanning them, which is the failure this
 * class exists to make impossible. Same shape as the payment stub's guard.
 *
 * To enable real scanning: run clamd, set `CLAMAV_HOST`, and the container
 * binding resolves `ClamAvScanner` instead of this.
 */
final class UnconfiguredScanner implements VirusScanner
{
    public function __construct(private readonly string $environment) {}

    public function assertClean(UploadedFile $file): void
    {
        if (in_array($this->environment, ['local', 'testing'], true)) {
            return;
        }

        throw new ScannerUnavailableException(
            'No malware scanner is configured. Set CLAMAV_HOST and run clamd before accepting uploads '
            .'(security audit T-6.1 finding H-4).'
        );
    }
}
