<?php
declare(strict_types=1);
use Illuminate\Foundation\Testing\RefreshDatabase;
uses(RefreshDatabase::class);

it('builds a placed pair with a paid self order', function () {
    $root = uiDistributor();
    $child = uiDistributor();
    uiPlaceUnder($root['id'], 'L', $child['id']);
    $orderId = uiPaidSelfOrder($child['id'], 60000, now());
    expect(\DB::table('genealogy_closure')->where('ancestor_id', $root['id'])->where('descendant_id', $child['id'])->value('depth'))->toBe(1)
        ->and($orderId)->toBeGreaterThan(0);
});
