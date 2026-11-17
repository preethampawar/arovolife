<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan §11.5 Lifetime Awards (client clarification 2026-11-17, user
 * instruction): the award is one cash amount per rank, paid once per lifetime
 * with the monthly payout on the 8th — no more Part A/B/C merchandise. Only the
 * month's highest rank earns an award; a lower rank not yet taken is paid in a
 * later month; a withdrawn award may be earned again, except after a
 * cooling-off cancellation or a termination. The markdown source
 * (database/seeders/content/compensation.md) carries the same text; this
 * migration brings the live page in line where it is already published.
 *
 * Replaces the section body from its heading up to the "operative from"
 * blockquote, which is kept as it stands (the live page may still carry an
 * older start-date sentence than the markdown). Runs only while the section
 * still holds the old Part A/B/C table: a page edited in the portal away from
 * it, or already migrated, is left alone and no audit row is written. The
 * replaced section is kept in the audit row (details.before).
 */
return new class extends Migration
{
    private const ACTION = 'plan.migration.rewrite_plan_lifetime_awards_section';

    public const HEADING = '<h3>11.5 Lifetime Awards &amp; Rewards</h3>';

    /** Only the pre-2026-11-17 section has this table header. */
    public const OLD_MARKER = '<th>Part A (1st)</th>';

    public const NEW_BODY = <<<'HTML'
<p>Each rank carries a Lifetime Award: <strong>one fixed cash amount</strong>, paid <strong>once per lifetime</strong> for that rank. Once a rank's award has been paid, it is never paid again.</p>
<p>Awards are funded from 20% of the BV of Company product sales. Every award traces to a rank qualification that was itself earned only from product sales. An award is paid in full, whatever that share comes to in the month.</p>
<p><strong>How it is earned.</strong> An award is earned for a month in which the Distributor is on that month's Rank Bonus roster under §11.2 (not held under the qualifying-again condition), whether or not the pool pays anything that month. Only the <strong>highest</strong> rank the Distributor achieves that month earns an award, and only if that rank's award has not been taken before.</p>
<ul>
<li><p>A lower rank whose award has not been taken is not paid in the month a higher rank is achieved. It is paid in a later month in which it is the highest rank the Distributor achieves, with no time limit.</p></li>
<li><p>Ranks 3 to 9 open only after the rank below has been achieved the required number of times (§11.2).</p></li>
<li><p>An award that is withdrawn before payment does not count as taken. It may be earned again in a later month in which that rank is the highest rank achieved. An award withdrawn because the Distributor cancelled during the cooling-off period or was terminated is not earned again.</p></li>
</ul>
<p><strong>When it is paid.</strong> An award is recorded on the 1st of the month after the month it is earned for, and paid to the Distributor's bank account with the monthly payout on the 8th. It is subject to the same payout conditions as other bonuses (§9); an award held under those conditions is kept in the web account and is not forfeited. An award that a later correction shows was not earned is withdrawn before it is paid.</p>
<p>No Distributor is assured of reaching any rank. The figures below describe the plan; they are not a statement of what any Distributor will receive, and the Company makes no representation about how many Distributors will reach any rank.</p>
<table>
<thead>
<tr>
<th>Rank</th>
<th>Lifetime Award (paid once)</th>
</tr>
</thead>
<tbody>
<tr><td>1 Silver Partner</td><td>₹15,400</td></tr>
<tr><td>2 Pearl Partner</td><td>₹36,000</td></tr>
<tr><td>3 Emerald Partner</td><td>₹1,08,000</td></tr>
<tr><td>4 Gold Partner</td><td>₹3,24,000</td></tr>
<tr><td>5 Diamond Partner</td><td>₹9,72,000</td></tr>
<tr><td>6 Blue Diamond Partner</td><td>₹28,26,000</td></tr>
<tr><td>7 Royal Diamond Partner</td><td>₹81,74,700</td></tr>
<tr><td>8 Crown Diamond Partner</td><td>₹2,37,06,000</td></tr>
<tr><td>9 Elite Diamond Partner</td><td>₹6,87,47,400</td></tr>
</tbody>
</table>
<p><strong>Award names.</strong> The Company may describe a rank's award by the name of an item, such as a phone or a trip. The name only describes the award: the award is paid as the cash amount shown above, no item is handed over, and there is no claim to the item or to its market price.</p>
<p><strong>Deductions.</strong> TDS is deducted at the rate that applies to bonuses (§8). Awards carry no repurchase-wallet deduction and no administrative charge, and do not count towards the cap in §10.</p>
HTML;

    /** Used only when the live section has no "operative from" blockquote. */
    public const DEFAULT_OPERATIVE = '<blockquote><p>Lifetime Awards &amp; Rewards are operative from the <strong>plan start date</strong> (§12).</p></blockquote>';

    public const OLD_S8 = 'Merchandise awards are not subject to the administrative charge. Applicable tax on merchandise awards is dealt with separately at the time of release.';

    public const NEW_S8 = 'Lifetime Awards (§11.5) carry no administrative charge; TDS is deducted from them as from other bonuses.';

    public function up(): void
    {
        DB::transaction(function (): void {
            $page = DB::table('content_pages')->where('slug', 'compensation')->lockForUpdate()->first(['id', 'body']);
            if ($page === null) {
                return;
            }

            $before = (string) $page->body;
            $start = strpos($before, self::HEADING);
            if ($start === false) {
                return;
            }

            $bodyStart = $start + strlen(self::HEADING);
            // The section runs to the next h2/h3 heading, or to the end of the page.
            preg_match('/<h[23][\s>]/', $before, $next, PREG_OFFSET_CAPTURE, $bodyStart);
            $end = isset($next[0]) ? (int) $next[0][1] : strlen($before);
            $section = substr($before, $bodyStart, $end - $bodyStart);

            if (! str_contains($section, self::OLD_MARKER)) {
                return;
            }

            $operative = preg_match_all('/<blockquote>.*?<\/blockquote>/s', $section, $quotes) > 0
                ? end($quotes[0])
                : self::DEFAULT_OPERATIVE;

            $newSection = "\n".self::NEW_BODY."\n".$operative."\n";
            $after = substr($before, 0, $bodyStart).$newSection.substr($before, $end);
            // §8 still describes merchandise awards; only the exact sentence is replaced.
            $after = str_replace(self::OLD_S8, self::NEW_S8, $after);

            DB::table('content_pages')->where('id', $page->id)->update(['body' => $after, 'updated_at' => now()]);

            // Through the model, not a raw insert: the creating hook links the
            // row into the audit hash chain, which a raw insert would skip.
            AuditLog::create([
                'actor_id' => null,
                'action' => self::ACTION,
                'subject_type' => 'content_page',
                'subject_id' => (int) $page->id,
                'before_hash' => AuditLog::digest($before),
                'after_hash' => AuditLog::digest($after),
                'details' => [
                    'migration' => '2026_11_17_100200_rewrite_plan_lifetime_awards_section',
                    'reason' => 'Client clarification 2026-11-17: §11.5 Lifetime Awards are one cash amount per rank, paid once per lifetime with the monthly payout; the Part A/B/C merchandise terms are replaced.',
                    'slug' => 'compensation',
                    'before' => $section,
                    'after' => $newSection,
                ],
            ]);
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Cannot roll back '.self::ACTION.': the previous §11.5 is in the audit row (details.before).'
        );
    }
};
