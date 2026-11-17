<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const DMO_MIGRATION = 'app/Modules/Content/Database/Migrations/2026_11_17_100000_drop_merchandise_only_wording_from_plan_awards.php';

function dmoMigration(): object
{
    return require base_path(DMO_MIGRATION);
}

function dmoSeedPlan(string $body): void
{
    DB::table('content_pages')->where('slug', 'compensation')->delete();
    DB::table('content_pages')->insert([
        'title' => 'Compensation Plan', 'slug' => 'compensation', 'type' => '', 'status' => 'published',
        'body' => $body, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('rewrites the §11.5 sentence and records an audit row', function (): void {
    $migration = dmoMigration();
    dmoSeedPlan('<h3>11.5 Lifetime Awards &amp; Rewards</h3><p>Each rank carries a Lifetime Award, '.$migration::OLD.'</p><p>Awards are funded from 20%.</p>');

    $migration->up();

    $body = (string) DB::table('content_pages')->where('slug', 'compensation')->value('body');
    expect($body)->toContain('Each rank carries a Lifetime Award, given as <strong>merchandise</strong>. An award cannot be exchanged or transferred.</p>')
        ->not->toContain('never cash')
        ->not->toContain('no cash alternative')
        ->not->toContain('converted to money')
        ->toContain('<p>Awards are funded from 20%.</p>');

    $audit = DB::table('audit_log')->where('action', 'plan.migration.drop_merchandise_only_wording_from_plan_awards')->sole();
    expect(strlen((string) $audit->before_hash))->toBe(32)
        ->and(strlen((string) $audit->after_hash))->toBe(32);
});

it('leaves a page without the old sentence untouched and writes no audit row', function (): void {
    dmoSeedPlan('<p>Edited in the portal.</p>');

    dmoMigration()->up();

    expect(DB::table('content_pages')->where('slug', 'compensation')->value('body'))->toBe('<p>Edited in the portal.</p>')
        ->and(DB::table('audit_log')->where('action', 'plan.migration.drop_merchandise_only_wording_from_plan_awards')->exists())->toBeFalse();
});

it('refuses to roll back', function (): void {
    dmoMigration()->down();
})->throws(RuntimeException::class);

it('renames non-cash awards to merchandise awards in §8', function (): void {
    $migration = require base_path('app/Modules/Content/Database/Migrations/2026_11_17_100100_rename_non_cash_awards_to_merchandise_awards_in_plan.php');
    dmoSeedPlan('<p>'.$migration::OLD.' TDS rates are those in force on the date of payment.</p>');

    $migration->up();
    $migration->up();

    expect(DB::table('content_pages')->where('slug', 'compensation')->value('body'))
        ->toBe('<p>Merchandise awards are not subject to the administrative charge. Applicable tax on merchandise awards is dealt with separately at the time of release. TDS rates are those in force on the date of payment.</p>')
        ->and(DB::table('audit_log')->where('action', 'plan.migration.rename_non_cash_awards_to_merchandise_awards_in_plan')->count())->toBe(1);
});
