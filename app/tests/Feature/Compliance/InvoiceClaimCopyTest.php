<?php

declare(strict_types=1);

/**
 * F58: the storefront promised a "GST invoice — issued for every order" while
 * the document actually issued says, correctly, that it is an order summary /
 * payment receipt and NOT a GST tax invoice (the GSTIN + CGST/SGST split is
 * deferred, R-28). Two consumer-facing statements contradicting each other on
 * a tax document is exactly what a customer complains about.
 *
 * Guards the buyer-facing views only: the admin worklist legitimately talks
 * about the GST invoice it will issue once R-28 ships.
 */
it('F58-01: no buyer-facing page promises a GST invoice while R-28 is deferred', function (string $view): void {
    $source = (string) file_get_contents(resource_path('views/'.$view));
    // Blade comments explain the rule; they are not shown to anyone.
    $rendered = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);

    expect(strtolower($rendered))->not->toContain('gst invoice');
})->with([
    'shop/index.blade.php',
    'shop/product.blade.php',
    'shop/cart.blade.php',
    'shop/checkout.blade.php',
    'landing/index.blade.php',
]);
