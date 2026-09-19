<?php

declare(strict_types=1);

use App\Modules\Compensation\Jobs\RebuildPeriodJob;
use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Services\Rebuild\RebuildKind;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class, WithConsoleEvents::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    app(RolesAndPermissionsSeeder::class)->run();
    Carbon::setTestNow(Carbon::parse('2026-09-17 10:00:00', 'Asia/Kolkata'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function rebuildJobDeveloper(): User
{
    $user = User::create([
        'full_name' => 'Platform Developer',
        'email' => 'rebuild-job-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole('developer');

    return $user;
}

it('runs on the compensation queue, once, with a timeout that fits the period', function (): void {
    $night = new RebuildPeriodJob(RebuildKind::Night->value, '2026-09-17', 1, 'chain-1');
    $month = new RebuildPeriodJob(RebuildKind::Month->value, '2026-08', 1, 'chain-2');

    expect($night->queue)->toBe('compensation');
    expect($night->tries)->toBe(1);
    expect($night->timeout)->toBe(3600);
    // The month's re-run is the seven crediting engines end to end.
    expect($month->timeout)->toBe(7200);
});

it('names the developer and the chain on every row the rebuild writes', function (): void {
    $developer = rebuildJobDeveloper();

    (new RebuildPeriodJob(RebuildKind::Night->value, '2026-09-17', $developer->id, 'chain-abc'))->handle();

    $rebuildRun = EngineRun::where('engine_key', 'compensation.rebuild-night')->firstOrFail();

    expect($rebuildRun->status)->toBe(EngineRun::STATUS_SUCCEEDED);
    expect($rebuildRun->trigger)->toBe(EngineRun::TRIGGER_MANUAL);
    expect($rebuildRun->actor_id)->toBe($developer->id);
    expect($rebuildRun->chain_id)->toBe('chain-abc');

    // The nested re-run inherits the same attribution: a run that moved derived
    // money must never read as a cron job.
    $nested = EngineRun::where('engine_key', 'compensation.nightly-run')->firstOrFail();

    expect($nested->trigger)->toBe(EngineRun::TRIGGER_MANUAL);
    expect($nested->actor_id)->toBe($developer->id);
    expect($nested->chain_id)->toBe('chain-abc');
});

it('leaves no attribution behind for the next job on the worker', function (): void {
    $developer = rebuildJobDeveloper();

    (new RebuildPeriodJob(RebuildKind::Night->value, '2026-09-17', $developer->id, 'chain-abc'))->handle();

    $context = app(EngineRunContext::class);

    expect($context->actorId())->toBeNull();
    expect($context->chainId())->toBeNull();
    expect($context->trigger())->toBe(EngineRun::TRIGGER_CONSOLE);
});

it('closes the rows a killed rebuild left running, by chain id', function (): void {
    $mine = EngineRun::create([
        'engine_key' => 'compensation.rebuild-month',
        'period_start' => '2026-08-01',
        'status' => EngineRun::STATUS_RUNNING,
        'trigger' => EngineRun::TRIGGER_MANUAL,
        'chain_id' => 'chain-dead',
        'started_at' => Carbon::now()->subMinutes(5),
    ]);
    $other = EngineRun::create([
        'engine_key' => 'compensation.nightly-run',
        'period_start' => '2026-09-17',
        'status' => EngineRun::STATUS_RUNNING,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'chain_id' => 'another-chain',
        'started_at' => Carbon::now()->subMinutes(5),
    ]);

    (new RebuildPeriodJob(RebuildKind::Month->value, '2026-08', 1, 'chain-dead'))
        ->failed(new RuntimeException('worker killed'));

    expect($mine->fresh()->status)->toBe(EngineRun::STATUS_FAILED);
    expect($mine->fresh()->error)->toContain('worker killed');
    expect($other->fresh()->status)->toBe(EngineRun::STATUS_RUNNING);
});
