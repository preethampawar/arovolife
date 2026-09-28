<?php

declare(strict_types=1);
use App\Modules\Compensation\Support\IncomeFilterDefaults;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

it('daily defaults to yesterday for both dates', function () {
    $r = Request::create('/x', 'GET');
    IncomeFilterDefaults::apply($r, 'daily', Carbon::parse('2026-09-28'));
    expect($r->query('from'))->toBe('2026-09-27')->and($r->query('to'))->toBe('2026-09-27');
});

it('monthly defaults to the previous month, across January', function () {
    $r = Request::create('/x', 'GET');
    IncomeFilterDefaults::apply($r, 'monthly', Carbon::parse('2027-01-05'));
    expect($r->query('from'))->toBe('2026-12')->and($r->query('to'))->toBe('2026-12');
});

it('an explicitly cleared form keeps blank dates', function () {
    $r = Request::create('/x', 'GET', ['f' => '1']);
    IncomeFilterDefaults::apply($r, 'daily', Carbon::parse('2026-09-28'));
    expect($r->filled('from'))->toBeFalse()->and($r->filled('to'))->toBeFalse();
});

it('an explicit range is respected', function () {
    $r = Request::create('/x', 'GET', ['from' => '2026-09-01', 'to' => '2026-09-10', 'f' => '1']);
    IncomeFilterDefaults::apply($r, 'daily', Carbon::parse('2026-09-28'));
    expect($r->query('from'))->toBe('2026-09-01');
});

it('a page link (?page=2) without f still gets defaults only when no dates present', function () {
    $r = Request::create('/x', 'GET', ['page' => '2']);
    IncomeFilterDefaults::apply($r, 'daily', Carbon::parse('2026-09-28'));
    expect($r->query('from'))->toBe('2026-09-27');
});

describe('pages', function () {
    uses(RefreshDatabase::class);

    it('GSB history opens on yesterday and a cleared form shows all rows', function () {
        Feature::activate(GenosSalesBonusFeature::class);
        $d = uiDistributor();
        $yesterday = Carbon::today('Asia/Kolkata')->subDay()->toDateString();

        $this->actingAs($d['user'])->get(route('income.gsb-history'))
            ->assertOk()
            ->assertSee('name="from" value="'.$yesterday.'"', false)
            ->assertSee('name="f" value="1"', false);

        $this->actingAs($d['user'])->get(route('income.gsb-history', ['f' => 1]))
            ->assertOk()
            ->assertSee('name="from" value=""', false);
    });
});
