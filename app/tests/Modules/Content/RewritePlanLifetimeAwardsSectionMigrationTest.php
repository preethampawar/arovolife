<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const RLA_ACTION = 'plan.migration.rewrite_plan_lifetime_awards_section';

function rlaMigration(): object
{
    return require base_path('app/Modules/Content/Database/Migrations/2026_11_17_100200_rewrite_plan_lifetime_awards_section.php');
}

function rlaSeedPlan(string $body): void
{
    DB::table('content_pages')->where('slug', 'compensation')->delete();
    DB::table('content_pages')->insert([
        'title' => 'Compensation Plan', 'slug' => 'compensation', 'type' => '', 'status' => 'published',
        'body' => $body, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function rlaBody(): string
{
    return (string) DB::table('content_pages')->where('slug', 'compensation')->value('body');
}

function rlaOldSection(string $operative): string
{
    return '<h3>11.5 Lifetime Awards &amp; Rewards</h3>'
        .'<p>Each rank carries a Lifetime Award, given as <strong>merchandise</strong>.</p>'
        .'<table><thead><tr><th>Rank</th><th>Part A (1st)</th><th>Part B (2nd)</th></tr></thead></table>'
        .'<p>Tax under section 194R applies to awards in kind.</p>'
        .$operative;
}

it('replaces the Part A/B/C section, keeps the live operative sentence and the rest of the page', function (): void {
    $operative = '<blockquote><p>Lifetime Awards &amp; Rewards are operative from <strong>8 November 2026</strong>.</p></blockquote>';
    $migration = rlaMigration();
    rlaSeedPlan('<p>'.$migration::OLD_S8.' TDS rates apply.</p><h3>11.4 ADC</h3><p>ADC text.</p>'.rlaOldSection($operative).'<h2>12. Changes to this plan</h2><p>Notice.</p>');

    $migration->up();

    $body = rlaBody();
    expect($body)
        ->toStartWith('<p>'.$migration::NEW_S8.' TDS rates apply.</p>')
        ->toContain('An award is paid in full, whatever that share comes to in the month.')
        ->toContain('do not count towards the cap in §10.')
        ->toContain('<h3>11.4 ADC</h3><p>ADC text.</p><h3>11.5 Lifetime Awards &amp; Rewards</h3>')
        ->toContain('paid <strong>once per lifetime</strong>')
        ->toContain('<tr><td>9 Elite Diamond Partner</td><td>₹6,87,47,400</td></tr>')
        ->toContain($operative."\n<h2>12. Changes to this plan</h2><p>Notice.</p>")
        ->not->toContain('Part A')
        ->not->toContain('194R')
        ->not->toContain('given as <strong>merchandise</strong>');

    $audit = DB::table('audit_log')->where('action', RLA_ACTION)->sole();
    expect(strlen((string) $audit->before_hash))->toBe(32)
        ->and(json_decode((string) $audit->details, true)['before'])->toContain('Part A (1st)');
});

it('adds the plan-start-date sentence when the section has no operative blockquote, at the end of the page', function (): void {
    rlaSeedPlan(rlaOldSection(''));

    rlaMigration()->up();

    expect(rlaBody())->toEndWith('(§12).</p></blockquote>'."\n")
        ->not->toContain('Part A');
});

it('runs once: a second run, or a page edited away from the old table, changes nothing', function (): void {
    rlaSeedPlan(rlaOldSection('<blockquote><p>Op.</p></blockquote>'));
    $migration = rlaMigration();
    $migration->up();
    $once = rlaBody();

    $migration->up();
    expect(rlaBody())->toBe($once)
        ->and(DB::table('audit_log')->where('action', RLA_ACTION)->count())->toBe(1);

    rlaSeedPlan('<h3>11.5 Lifetime Awards &amp; Rewards</h3><p>Edited in the portal.</p>');
    $migration->up();
    expect(rlaBody())->toBe('<h3>11.5 Lifetime Awards &amp; Rewards</h3><p>Edited in the portal.</p>')
        ->and(DB::table('audit_log')->where('action', RLA_ACTION)->count())->toBe(1);
});

it('refuses to roll back', function (): void {
    rlaMigration()->down();
})->throws(RuntimeException::class);
