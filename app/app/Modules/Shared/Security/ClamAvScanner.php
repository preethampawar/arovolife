<?php

declare(strict_types=1);

namespace App\Modules\Shared\Security;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Streams an upload to a clamd daemon over TCP.
 *
 * INSTREAM rather than SCAN: SCAN passes a path and needs the daemon to share
 * a filesystem with the application, which is false the moment either runs in
 * its own container. Streaming the bytes works in every topology.
 *
 * **Fails closed.** If the daemon cannot be reached this throws rather than
 * returning clean, and the upload is refused. A scanner that silently passes
 * everything when it is down is worse than none, because the platform then
 * believes uploads are scanned.
 */
final class ClamAvScanner implements VirusScanner
{
    /** clamd's default INSTREAM chunk ceiling is 1 MiB; stay well under it. */
    private const CHUNK = 8192;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $timeoutSeconds,
    ) {}

    public function assertClean(UploadedFile $file): void
    {
        $socket = @fsockopen($this->host, $this->port, $code, $message, $this->timeoutSeconds);

        if ($socket === false) {
            throw new ScannerUnavailableException(
                "Could not reach clamd at {$this->host}:{$this->port} ({$code}: {$message})."
            );
        }

        stream_set_timeout($socket, $this->timeoutSeconds);

        try {
            $response = $this->stream($socket, $file);
        } finally {
            fclose($socket);
        }

        if (str_contains($response, 'OK') && ! str_contains($response, 'FOUND')) {
            return;
        }

        if (str_contains($response, 'FOUND')) {
            // The filename is deliberately absent from the log line: it is
            // user-supplied and can itself carry PII ("aadhaar-scan.pdf").
            $signature = trim(str_replace(['stream:', 'FOUND'], '', $response));
            Log::warning('Malware detected in upload', ['signature' => $signature]);

            throw new InfectedFileException($signature);
        }

        throw new ScannerUnavailableException("Unexpected clamd response: {$response}");
    }

    /**
     * @param  resource  $socket
     */
    private function stream($socket, UploadedFile $file): string
    {
        fwrite($socket, "zINSTREAM\0");

        $handle = fopen($file->getRealPath(), 'rb');

        if ($handle === false) {
            throw new ScannerUnavailableException('Could not read the uploaded file for scanning.');
        }

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, self::CHUNK);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                // Each chunk is length-prefixed as a 4-byte big-endian int.
                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }
        } finally {
            fclose($handle);
        }

        // A zero-length chunk terminates the stream.
        fwrite($socket, pack('N', 0));

        return (string) fgets($socket, 4096);
    }
}
