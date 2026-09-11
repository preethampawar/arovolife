<?php

declare(strict_types=1);

/**
 * Upload-handling switches.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Malware scanning
    |--------------------------------------------------------------------------
    |
    | Defaults to ON, so an environment that never heard of this switch keeps
    | the fail-closed behaviour it had: no scanner means no upload. The client
    | decided on 2026-09-11 that staging and production run without ClamAV and
    | that an upload must never be blocked by a missing scanner, so those
    | environments set `CLAMAV_ENABLED=false` and every upload they accept is
    | unscanned — logged as `uploads.scan_skipped`, and carried on the risk
    | register as R-83.
    |
    | This switch turns the scan off. It never turns a *failing* scan into a
    | pass: with scanning on, an infected file and an unavailable scanner both
    | still refuse the upload.
    |
    */

    'malware_scan' => filter_var(env('CLAMAV_ENABLED', true), FILTER_VALIDATE_BOOL),

];
