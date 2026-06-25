<?php

declare(strict_types=1);

use App\Modules\Compensation\Services\PersonalBvTitleService;

it('returns null title below 3000 BV', function () {
    $svc = new PersonalBvTitleService;
    $result = $svc->forBvPaise(299_999); // 2,999.99 BV
    expect($result->title)->toBeNull();
    expect($result->maxGsbSlab)->toBe(0);
    expect($result->nextTitleBvPaise)->toBe(300_000);
});

it('returns Retailer at exactly 3000 BV', function () {
    $svc = new PersonalBvTitleService;
    $result = $svc->forBvPaise(300_000); // 3,000 BV
    expect($result->title)->toBe('Retailer');
    expect($result->maxGsbSlab)->toBe(1);
    expect($result->nextTitleBvPaise)->toBe(500_000);
});

it('returns Dealer at 5000 BV', function () {
    $svc = new PersonalBvTitleService;
    $result = $svc->forBvPaise(500_000);
    expect($result->title)->toBe('Dealer');
    expect($result->maxGsbSlab)->toBe(2);
});

it('returns Wholesaler at 15000 BV', function () {
    $svc = new PersonalBvTitleService;
    $result = $svc->forBvPaise(1_500_000);
    expect($result->title)->toBe('Wholesaler');
    expect($result->maxGsbSlab)->toBe(3);
});

it('returns Global Distributor at 300000 BV with no next title', function () {
    $svc = new PersonalBvTitleService;
    $result = $svc->forBvPaise(30_000_000);
    expect($result->title)->toBe('Global Distributor');
    expect($result->maxGsbSlab)->toBe(7);
    expect($result->nextTitleBvPaise)->toBeNull();
});
