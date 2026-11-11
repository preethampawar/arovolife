<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Providers\Money\AwardRequalificationCheckProvider;
use App\Modules\Compensation\Models\LifetimeAwardMilestone;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(AwardRequalificationCheckProvider::class);
});

function requalificationAward(Distributor $distributor, string $qualificationStatus): WalletLedgerEntry
{
    RankQualification::create([
        'distributor_id' => $distributor->id, 'rank_number' => 2, 'month_start' => '2026-08-01',
        'occurrence_in_month' => 1, 'is_carry_forward' => false, 'status' => $qualificationStatus,
    ]);

    $milestone = LifetimeAwardMilestone::create([
        'distributor_id' => $distributor->id, 'rank_number' => 2, 'tranche' => 1, 'amount_paise' => 3_000_000,
        'triggered_month' => '2026-08-01', 'achieved_month' => '2026-08-01', 'qualification_count' => 1,
        'award_description' => 'Pearl Partner Lifetime Award', 'status' => LifetimeAwardMilestone::STATUS_CREDITED,
    ]);

    return WalletLedgerEntry::create([
        'distributor_id' => $distributor->id, 'type' => 'awards_credit', 'amount_paise' => 3_000_000,
        'reference_id' => $milestone->id, 'reference_type' => 'lifetime_award_milestone', 'bonus_month' => '2026-08-01',
    ]);
}

it('is silent while every credited award still stands on its qualification', function (): void {
    requalificationAward(Distributor::factory()->create(), RankQualification::STATUS_QUALIFIED);

    expect($this->provider->count())->toBe(0);
});

it('surfaces an unpaid award whose rank qualification was voided', function (): void {
    $distributor = Distributor::factory()->create();
    $entry = requalificationAward($distributor, RankQualification::STATUS_VOIDED);

    $item = $this->provider->items()->first();

    expect($this->provider->count())->toBe(1)
        ->and($item->subjectId)->toBe($entry->id)
        ->and($item->title)->toContain($distributor->adn)
        ->and($item->meta['amount_paise'])->toBe(3_000_000);
});
