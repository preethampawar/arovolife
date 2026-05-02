<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\CoolingOffEvent;
use App\Modules\Compliance\Services\SendCoolingOffReminders;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/**
 * Statutory reminder cron: at 20, 7 and 1 day(s) before the cooling-off
 * window expires the distributor must be reminded so they don't miss
 * their right to cancel (T&C §4 + risk register R-04). Each milestone
 * fires exactly once per distributor — driven by per-milestone columns
 * on cooling_off_events so a re-run of the cron is a no-op.
 */
function corSeed(int $daysFromNow, ?array $sent = null): array
{
    $user = User::create([
        'email' => 'cor-'.rand(1000, 9999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'full_name' => 'Cor Test',
    ]);

    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    try {
        // $daysFromNow = days the distributor has already been in the window.
        // So end_at lands at now + (30 - daysFromNow) and "days remaining" =
        // 30 - $daysFromNow.
        $effective = now()->subDays($daysFromNow);
        $end = $effective->copy()->addDays(30);
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => 'COR'.rand(100000, 999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0,
            'placement_parent_id' => 0,
            'placement_side' => null,
            'side_chosen_by' => 'referral_default',
            'depth' => 0,
            'effective_date' => $effective->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => $end->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $id)->update([
            'sponsor_id' => $id, 'placement_parent_id' => $id,
        ]);
    } finally {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0,
    ]);

    $row = CoolingOffEvent::create([
        'distributor_id' => $id,
        'opened_at' => $effective,
        'reminder_d20_sent_at' => $sent['d20'] ?? null,
        'reminder_d7_sent_at' => $sent['d7'] ?? null,
        'reminder_d1_sent_at' => $sent['d1'] ?? null,
    ]);

    return ['user' => $user, 'distributor_id' => $id, 'event' => $row];
}

it('COR-01: D-20 reminder fires for a distributor 10 days into the window (20 days remaining)', function () {
    Notification::fake();

    [$user, $id, $row] = array_values(corSeed(daysFromNow: 10));

    app(SendCoolingOffReminders::class)();

    $row->refresh();
    expect($row->reminder_d20_sent_at)->not->toBeNull()
        ->and($row->reminder_d7_sent_at)->toBeNull()
        ->and($row->reminder_d1_sent_at)->toBeNull();
});

it('COR-02: D-7 reminder fires at 7 days remaining; D-20 already sent stays untouched', function () {
    Notification::fake();

    [$user, $id, $row] = array_values(corSeed(
        daysFromNow: 23,
        sent: ['d20' => now()->subDays(13)],
    ));

    app(SendCoolingOffReminders::class)();

    $row->refresh();
    expect($row->reminder_d7_sent_at)->not->toBeNull();
});

it('COR-03: D-1 reminder fires at 1 day remaining', function () {
    Notification::fake();

    [$user, $id, $row] = array_values(corSeed(
        daysFromNow: 29,
        sent: ['d20' => now()->subDays(19), 'd7' => now()->subDays(6)],
    ));

    app(SendCoolingOffReminders::class)();

    $row->refresh();
    expect($row->reminder_d1_sent_at)->not->toBeNull();
});

it('COR-04: rerun is idempotent — second invocation does not double-send', function () {
    Notification::fake();

    [$user, $id, $row] = array_values(corSeed(daysFromNow: 10));

    app(SendCoolingOffReminders::class)();
    $first = CoolingOffEvent::find($row->id)->reminder_d20_sent_at;

    app(SendCoolingOffReminders::class)();
    $second = CoolingOffEvent::find($row->id)->reminder_d20_sent_at;

    // Same instant on both runs — second invocation skipped because the
    // column was already non-NULL. equalTo compares the underlying instant,
    // not its serialised form, so we don't need a sleep to make timestamps differ.
    expect($first->equalTo($second))->toBeTrue();
});

it('COR-05: a cancelled cooling-off does not receive any reminders', function () {
    Notification::fake();

    [$user, $id, $row] = array_values(corSeed(daysFromNow: 10));
    $row->update(['cancelled_at' => now()]);

    app(SendCoolingOffReminders::class)();

    $row->refresh();
    expect($row->reminder_d20_sent_at)->toBeNull();
});

it('COR-06: catch-up — a cron outage doesn\'t permanently lose a missed milestone', function () {
    Notification::fake();

    // Distributor has 5 days remaining (i.e. the D-7 milestone moment was
    // 2 days ago). The cron was offline that day. Today's run must still
    // fire D-7 because the milestone has been reached/passed and the
    // column is still NULL.
    [$user, $id, $row] = array_values(corSeed(daysFromNow: 25));

    app(SendCoolingOffReminders::class)();

    $row->refresh();
    expect($row->reminder_d7_sent_at)->not->toBeNull();
});

it('COR-07: no reminder fires after the window has fully closed', function () {
    Notification::fake();

    // Cooling-off ended yesterday — too late to remind.
    [$user, $id, $row] = array_values(corSeed(daysFromNow: 31));

    app(SendCoolingOffReminders::class)();

    $row->refresh();
    expect($row->reminder_d20_sent_at)->toBeNull()
        ->and($row->reminder_d7_sent_at)->toBeNull()
        ->and($row->reminder_d1_sent_at)->toBeNull();
});
