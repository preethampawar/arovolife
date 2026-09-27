<?php
declare(strict_types=1);
use Illuminate\Foundation\Testing\RefreshDatabase;
uses(RefreshDatabase::class);

it('distributor chrome says My Personal World and My Business World', function () {
    $d = uiDistributor();
    $html = $this->actingAs($d['user'])->get(route('my-business'))->assertOk()->getContent();
    expect($html)->toContain('My Personal World')->toContain('My Business World')
        ->not->toMatch('/>\s*My Dashboard\s*</')
        ->not->toMatch('/>\s*My Business\s*</');
});
