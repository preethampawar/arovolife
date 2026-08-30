<?php

declare(strict_types=1);

use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TeamStatsService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/**
 * Inserts a distributor row (+ its self closure row) and, when a parent is
 * given, the closure rows that make it a Genos descendant of that parent.
 */
function tssDistributor(?int $parentId = null, ?CarbonInterface $effectiveDate = null, string $side = 'L'): int
{
    $user = User::create([
        'full_name' => 'TSS User',
        'email' => 'tss-'.uniqid().'@example.com',
        'phone_e164' => '+91944'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        'password_hash' => Hash::make('tss-test-pwd-2026'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $effectiveDate ??= now();

    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => (string) random_int(100000000, 999999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => $parentId ?? 0,
            'placement_parent_id' => $parentId ?? 0,
            'placement_side' => $parentId === null ? null : $side,
            'side_chosen_by' => 'referral_default',
            'depth' => $parentId === null ? 0 : 1,
            'effective_date' => $effectiveDate->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => $effectiveDate->copy()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        if ($parentId === null) {
            DB::table('distributors')->where('id', $id)->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);
        }
    } finally {
        enableTestForeignKeys();
    }

    DB::table('genealogy_closure')->insert(['ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0]);
    if ($parentId !== null) {
        $ancestors = DB::table('genealogy_closure')->where('descendant_id', $parentId)->get();
        foreach ($ancestors as $row) {
            DB::table('genealogy_closure')->insert([
                'ancestor_id' => $row->ancestor_id, 'descendant_id' => $id, 'depth' => $row->depth + 1,
            ]);
        }
    }

    return $id;
}

it('joinedPerDay zero-fills 30 days and counts only the Genos downline', function (): void {
    $rootId = tssDistributor();
    $childId = tssDistributor($rootId, now());
    tssDistributor($childId, now()->subDays(10));
    tssDistributor($rootId, now()->subDays(45), 'R');   // outside the window
    tssDistributor(null, now());                    // unrelated root — not in the downline

    $series = app(TeamStatsService::class)->joinedPerDay(Distributor::findOrFail($rootId), 30);

    expect($series)->toHaveCount(30)
        ->and(array_key_first($series))->toBe(now()->subDays(29)->toDateString())
        ->and(array_key_last($series))->toBe(now()->toDateString())
        ->and($series[now()->toDateString()])->toBe(1)
        ->and($series[now()->subDays(10)->toDateString()])->toBe(1)
        ->and(array_sum($series))->toBe(2);
});
