<?php

declare(strict_types=1);

use App\Modules\Shared\Rules\NoIncomeProjection;

/**
 * Hard rule 3 / DSR 2021 Rule 5(1)(d) — the guard on administrator-written copy.
 *
 * The staging QA run (F113) published "You can earn ₹50,000 per month
 * guaranteed with arovolife." through the content editor untouched: the rule
 * was a substring match over a phrase list, and the amount in the middle of
 * the sentence splits every phrase on it. The first test below is that exact
 * sentence; the rest are the ways the same claim gets rewritten, and the ways
 * legitimate copy is written so that the guard does not refuse it.
 */
it('refuses the sentence that got through on staging', function (): void {
    expect(NoIncomeProjection::firstBannedPhrase('You can earn ₹50,000 per month guaranteed with arovolife.'))
        ->not->toBeNull();
});

it('refuses the same claim however it is worded', function (string $copy): void {
    expect(NoIncomeProjection::firstBannedPhrase($copy))->not->toBeNull();
})->with([
    'amount and period' => 'Earn ₹25,000 per month from your Genos.',
    'slash period' => 'Make Rs 2,000/day with a small team.',
    'adverb period' => 'Our top distributors earn ₹1,00,000 monthly.',
    'article period' => 'You could make INR 40,000 a week once you are Gold.',
    'inside markup' => '<p>You can <strong>earn ₹50,000</strong> per month.</p>',
    'no amount' => 'Join now and earn every single day.',
    'guarantee after the number' => 'A payout of ₹15,000 every month, assured.',
    'guarantee before the number' => 'Guaranteed ₹9,999 in your first week.',
    'guarantee and a noun' => 'The plan gives you a guaranteed return.',
    'fixed on the noun' => 'A fixed monthly income for every Silver rank.',
    'percentage of return' => 'A 12% monthly return on your first purchase.',
    'percentage with a promise' => 'Assured 20% returns for the first three months.',
]);

it('leaves legitimate copy alone', function (string $copy): void {
    expect(NoIncomeProjection::firstBannedPhrase($copy))->toBeNull();
})->with([
    // Caps and ceilings: a limit on what the company pays is the opposite of
    // a promise about what somebody makes, and DSA §6.2 requires them to be
    // published. All four are copy the platform already ships.
    'repurchase cap' => 'The repurchase deduction (10% of each bonus, up to ₹10,000 a month) is taken when the bonus is credited.',
    'adc cap' => 'The ADC Bonus is 3% of their combined BV, capped at ₹1,00,000/month.',
    'plan cap' => 'Total distributor-side commission capped at ₹50,00,000 per Distributor per month.',
    'admin charge' => 'The 3% admin charge (max ₹25,000 per weekly batch) and 5% TDS are applied at transfer.',
    // The payout calendar is a fact about when money moves, not a claim that
    // any will.
    'payout cadence' => 'Weekly income for each Wednesday-to-Tuesday earning week is paid on the following Tuesday.',
    'accounting window' => 'The ₹10,000 ceiling is counted per month of income earned, not per payment.',
    // The disclaimer itself has to stay writable.
    'disclaimer' => 'Meeting them is not a guarantee of any income.',
    'zero is possible' => "A level's point value may be ₹0, in which case no Fortune Bonus is payable for that month.",
]);
