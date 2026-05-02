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
 * file whose magic-byte signature doesn't match an allow-list (JPEG, PNG,
 * PDF). Production hardening as well — never trust the client.
 */
final class ValidUploadedDocumentBytes implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail("The {$attribute} must be a valid file.");

            return;
        }

        $head = (string) file_get_contents($value->getRealPath(), false, null, 0, 8);
        if ($head === '') {
            $fail("The {$attribute} appears to be empty.");

            return;
        }

        $isJpeg = str_starts_with($head, "\xFF\xD8\xFF");
        $isPng = str_starts_with($head, "\x89PNG\r\n\x1A\n");
        $isPdf = str_starts_with($head, '%PDF-');

        if (! ($isJpeg || $isPng || $isPdf)) {
            $fail("The {$attribute} must be a JPEG, PNG, or PDF file.");
        }
    }
}
