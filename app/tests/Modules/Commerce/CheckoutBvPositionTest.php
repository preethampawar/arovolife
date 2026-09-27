<?php
// tests/Modules/Commerce/CheckoutBvPositionTest.php
declare(strict_types=1);

it('renders Total BV directly under the Order Summary heading', function () {
    $blade = file_get_contents(resource_path('views/shop/checkout.blade.php'));
    $heading = strpos($blade, 'Order Summary</h2>');
    $bv = strpos($blade, '$bvTotal = auth()->user()->distributor');
    $firstLine = strpos($blade, '@foreach($cart->items', $heading);
    expect($heading)->not->toBeFalse()
        ->and($bv)->toBeGreaterThan($heading)
        ->and($bv)->toBeLessThan($firstLine);
});
