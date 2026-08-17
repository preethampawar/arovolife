<?php

declare(strict_types=1);

namespace App\Modules\Shared\Security;

use RuntimeException;

/** The scanner identified the upload as malicious. */
final class InfectedFileException extends RuntimeException
{
    public function __construct(public readonly string $signature)
    {
        // The signature name goes in the exception for the log, never into
        // the message shown to the uploader: telling somebody which signature
        // fired tells them what to change.
        parent::__construct('The uploaded file was rejected by the malware scanner.');
    }
}
