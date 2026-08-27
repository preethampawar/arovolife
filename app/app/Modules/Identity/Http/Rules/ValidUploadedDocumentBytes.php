<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Defence-in-depth on file uploads. Laravel's `mimetypes:` rule calls
 * UploadedFile::getMimeType(), which in unit tests returns whatever string
 * the test fake declared — letting bogus bodies labelled as image/jpeg slip
 * through. This rule reads the first few bytes from disk and rejects any
 * file whose magic-byte signature doesn't match an allow-list.
 * Production hardening as well — never trust the client.
 *
 * The allowed set is a constructor argument because the KYC paths and the
 * grievance paths legitimately differ: an identity document is a scan or a
 * PDF, while grievance evidence is often a phone screenshot, which on iOS is
 * HEIC and on Android is increasingly WebP. Before this was parameterised the
 * grievance controllers simply had no byte check at all (T-6.1 finding H-4) —
 * a narrower rule that cannot be applied gets skipped, and a skipped rule is
 * worth nothing.
 */
final class ValidUploadedDocumentBytes implements ValidationRule
{
    /** Identity documents: a scan or a PDF. */
    public const DOCUMENTS = ['jpeg', 'png', 'pdf'];

    /** Grievance evidence: the above plus what a phone actually produces. */
    public const EVIDENCE = ['jpeg', 'png', 'pdf', 'webp', 'heic'];

    /** @param array<int, string> $allowed */
    public function __construct(private readonly array $allowed = self::DOCUMENTS) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail("The {$attribute} must be a valid file.");

            return;
        }

        // 12 bytes: enough for the longest signature checked here (HEIC's
        // `ftyp` box, which sits at offset 4).
        $head = (string) file_get_contents($value->getRealPath(), false, null, 0, 12);

        if ($head === '') {
            $fail("The {$attribute} appears to be empty.");

            return;
        }

        if ($this->detect($head) === null) {
            $fail("The {$attribute} must be a ".$this->humanList().' file.');
        }
    }

    /** The format the bytes actually are, or null if it is not an allowed one. */
    private function detect(string $head): ?string
    {
        $matches = [
            'jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
            'png' => str_starts_with($head, "\x89PNG\r\n\x1A\n"),
            'pdf' => str_starts_with($head, '%PDF-'),
            // RIFF....WEBP — the size field sits between the two markers.
            'webp' => str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP',
            // ISO-BMFF: a 4-byte box size, then 'ftyp', then the brand.
            'heic' => substr($head, 4, 4) === 'ftyp'
                && in_array(substr($head, 8, 4), ['heic', 'heix', 'hevc', 'mif1', 'msf1'], true),
        ];

        foreach ($this->allowed as $format) {
            if ($matches[$format] ?? false) {
                return $format;
            }
        }

        return null;
    }

    private function humanList(): string
    {
        $formats = array_map(strtoupper(...), $this->allowed);
        $last = array_pop($formats);

        return $formats === [] ? $last : implode(', ', $formats).' or '.$last;
    }
}
