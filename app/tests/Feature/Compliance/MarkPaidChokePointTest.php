<?php

declare(strict_types=1);

/**
 * Hard rule 2, enforced rather than commented: `OrderStateMachine::markPaid()`
 * accrues BV and fires the compensation engines, so it may be called from
 * exactly one place — `PaymentConfirmationService`, which verifies the
 * consideration first. A second caller is a way to manufacture commission
 * liability without a sale, whatever it was written for.
 */
it('markPaid() is called only from PaymentConfirmationService', function () {
    $root = base_path('app');
    $offenders = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        if (str_ends_with($path, 'Modules/Payments/Services/PaymentConfirmationService.php')) {
            continue;
        }
        $source = (string) file_get_contents($path);
        // Strip comments so a docblock mentioning the method does not count.
        $code = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $source);
        if (preg_match('/->\s*markPaid\s*\(/', $code) === 1) {
            $offenders[] = str_replace($root.'/', '', $path);
        }
    }

    expect($offenders)->toBe([]);
});
