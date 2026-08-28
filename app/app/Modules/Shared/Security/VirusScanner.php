<?php

declare(strict_types=1);

namespace App\Modules\Shared\Security;

use Illuminate\Http\UploadedFile;

/**
 * Scans an uploaded file before it is stored (T-6.1 finding H-4).
 *
 * Every upload path on the platform — grievance evidence, KYC documents, ID
 * photos, admin postal scans — stored unscanned bytes. The compensating
 * controls are real (private bucket, never web-served, RBAC and audit on the
 * stream routes, short-lived signed URLs), so the live risk is not a served
 * payload: it is a malicious file landing on a compliance officer's desktop
 * when they open the evidence for a complaint. That is a person the platform
 * asked to open it.
 */
interface VirusScanner
{
    /**
     * @throws InfectedFileException when the file is infected
     * @throws ScannerUnavailableException when no scanner could examine it
     */
    public function assertClean(UploadedFile $file): void;
}
