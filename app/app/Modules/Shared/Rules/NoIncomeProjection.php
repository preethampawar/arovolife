<?php

declare(strict_types=1);

namespace App\Modules\Shared\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects copy that implies a future income.
 *
 * DSR 2021 Rule 5(1)(d) and Code IV.XII.a forbid income projections on any
 * surface a prospect or a distributor reads. PublicCopyAuditTest has enforced
 * that against Blade templates since Phase 1 — but a template is written by an
 * engineer and reviewed in a diff, while an announcement is written by an
 * administrator in a textarea and reaches every distributor the moment it is
 * published. The build-time audit cannot see it. So the same list guards both,
 * and it lives here rather than in the test so the two cannot drift: the test
 * reads {@see BANNED_PHRASES} as its source.
 *
 * Two checks run, in order. {@see BANNED_PHRASES} is a substring match on the
 * lowercased body — the phrases are the ones a real person actually writes.
 * {@see BANNED_PATTERNS} then matches the *shapes* a phrase list structurally
 * cannot see, because the sentence carries a number in the middle of it. Both
 * run against the copy with its markup stripped, so a tag cannot split a
 * phrase in two.
 */
final class NoIncomeProjection implements ValidationRule
{
    /**
     * Phrases that imply a future income.
     *
     * The second group was added on 2026-08-17 after the C-02 sign-off found
     * "Income amounts shown in any plan illustration represent maximum
     * achievable levels based on historical top performer data" inside the
     * registration consent step. None of the original phrases would have
     * caught it: the pattern is not a promise of a number, it is a
     * characterisation of the plan's numbers by reference to what the best
     * earners have made, which is the more common way this rule gets broken.
     *
     * @var list<string>
     */
    public const BANNED_PHRASES = [
        'guaranteed income',
        'assured income',
        'earn upto',
        'earn up to',
        'earn per day',
        'earn per month',
        'earn every month',
        'monthly income guaranteed',
        'passive income',
        'unlimited earnings',
        'become rich',
        'get rich',
        'top performer',
        'top earner',
        'maximum achievable',
        'typical results',
        'plan illustration',
        'income illustration',
        'potential earnings',
        'earning potential',
        'expected income',
        'average income',
        'average earnings',
    ];

    /**
     * Shapes that imply a future income without using any listed phrase.
     *
     * Added 2026-09-11 after the staging QA run (F113) published
     * "You can earn ₹50,000 per month guaranteed with arovolife." through the
     * content editor untouched: the amount splits `earn per month`, and the
     * word order splits `guaranteed income`. A list of phrases cannot see a
     * sentence built out of the same idea in a different order, and the
     * sentence a real person writes always carries the number.
     *
     * So the shapes below are matched as patterns rather than phrases,
     * and each one is deliberately narrow — an amount or a percentage next to
     * a period of time, an earning verb next to a period of time, or a
     * guarantee next to a word for money. Each pattern refuses to cross a
     * sentence boundary (`[^.!?\n]`), because two unrelated sentences either
     * side of a full stop are not a claim.
     *
     * A false positive here costs an administrator a rewording; a false
     * negative is a published income projection under DSR 2021 Rule 5(1)(d).
     * The trade is made in that direction on purpose.
     *
     * @var list<string>
     */
    public const BANNED_PATTERNS = [
        // An earning, then an amount of money, then a period of time:
        // "you can earn ₹50,000 per month", "make Rs 2,000/day".
        //
        // The earning word is required. An amount against a period on its own
        // is how the plan states a *cap* — "up to ₹10,000 a month" (the
        // repurchase deduction), "capped at ₹1,00,000/month" (ADC) — and a
        // ceiling is a limit on what the company will pay, the opposite of a
        // promise about what somebody will make. Refusing those would make the
        // caps unpublishable, which DSA §6.2 requires them to be.
        '/\b(?:earn|earns|earned|earning|earnings|make|makes|making|income|salary)\b[^.!?\n]{0,60}?'
            .'(?:₹|\brs\.?|\binr\b)\s*[\d,]+(?:\.\d+)?\s*(?:k|lakhs?|crores?|thousand|million)?[^.!?\n]{0,20}?'
            .self::PERIOD.'/iu',

        // An earning against a period of time with no amount at all:
        // "earn each month", "making a day". Held to a short gap, and to the
        // verb forms only: "counted per month of income earned" is accounting,
        // and adverbs are left out entirely because "your income is paid
        // weekly" is a fact about the payout calendar that several pages
        // state.
        '/\b(?:earn|earns|earning|make|makes|making)\b[^.!?\n]{0,15}?'
            .'\b(?:per|a|an|each|every)\s+(?:\w+\s+)?(?:day|week|month|year|annum)\b(?!-)/iu',

        // A guarantee against money. "fixed" must sit directly on the noun:
        // "we fixed the payout report" is an announcement somebody will write.
        '/\b(?:guarantee|guaranteed|guarantees|assured|assures|promised|risk[\s-]?free)\b[^.!?\n]{0,30}?'
            .'\b(?:income|incomes|earning|earnings|return|returns|profit|profits|payout|payouts|salary|money)\b/iu',
        '/\bfixed\s+(?:daily\s+|weekly\s+|monthly\s+|yearly\s+|guaranteed\s+)?'
            .'(?:income|earning|earnings|return|returns|profit|profits|salary)\b/iu',
        // …and the same idea with the number between the two:
        // "₹50,000 per month guaranteed", "guaranteed ₹50,000".
        '/(?:₹|\brs\.?|\binr\b)\s*[\d,]+[^.!?\n]{0,40}?\b(?:guaranteed|assured|promised)\b/iu',
        '/\b(?:guaranteed|assured|promised)\b[^.!?\n]{0,20}?(?:₹|\brs\.?|\binr\b)\s*[\d,]+/iu',

        // A percentage of return. Scoped tightly to the return nouns: the
        // plan copy is full of legitimate percentages (3% admin charge, 5%
        // TDS, 45% of the daily pool) and none of them is a promise.
        '/\b\d{1,3}(?:\.\d+)?\s*(?:%|per\s?cent)\s*(?:guaranteed\s+|assured\s+)?'
            .'(?:daily\s+|weekly\s+|monthly\s+|yearly\s+|annual\s+)?(?:return|returns|roi|profit|profits|interest)\b/iu',
        '/\b(?:guaranteed|assured|fixed|upto|up\s+to)\s+\d{1,3}(?:\.\d+)?\s*(?:%|per\s?cent)/iu',
    ];

    /** A period of time, in the forms copy actually writes one. */
    private const PERIOD = '(?:\/\s*(?:day|week|month|year)\b|\b(?:per|a|an|each|every)\s+(?:\w+\s+)?(?:day|week|month|year|annum)\b(?!-)|\b(?:daily|weekly|monthly|yearly)\b)';

    /**
     * Sentences the patterns would refuse that the company has published on
     * purpose, as a statement of what the rule forbids rather than a claim.
     *
     * Kept as whole phrases, matched on the normalised copy and removed from
     * it before the patterns run — never as a regex exemption, because a
     * pattern clever enough to tell a prohibition from a promise is a pattern
     * that can be talked round. Every entry is a sentence somebody read and
     * signed off; adding one is a decision, not a workaround. This mirrors
     * PublicCopyAuditTest::$reviewedExceptions, which does the same job for
     * the phrase list on Blade templates.
     *
     * @var list<string>
     */
    public const REVIEWED_EXCEPTIONS = [
        // The disclaimer the platform already publishes, in the two forms it
        // is published in: `resources/views/income/rank-bonus.blade.php`
        // ("Meeting them is not a guarantee of any income.") and
        // `database/seeders/content/terms.md` §17 ("…nor any projection or
        // guarantee of income, earnings, or balances…"). An FAQ answer or an
        // announcement saying the same thing must stay writable — the
        // disclaimer is the copy the rule exists to protect.
        'not a guarantee of any income',
        'not a guarantee of income',
        'no guarantee of any income',
        'no guarantee of income',
        'guarantee of income, earnings, or balances',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $found = self::firstBannedPhrase($value);

        if ($found !== null) {
            $fail(
                'This wording implies a future income, which we may not publish — the phrase is "'.$found.'". '
                .'Say what has happened, not what someone might earn.'
            );
        }
    }

    /** The first banned phrase or pattern match in the given copy, or null if it is clean. */
    public static function firstBannedPhrase(string $copy): ?string
    {
        $haystack = self::normalise($copy);

        foreach (self::BANNED_PHRASES as $phrase) {
            if (str_contains($haystack, $phrase)) {
                return $phrase;
            }
        }

        foreach (self::REVIEWED_EXCEPTIONS as $exception) {
            $haystack = str_replace($exception, '', $haystack);
        }

        foreach (self::BANNED_PATTERNS as $pattern) {
            if (preg_match($pattern, $haystack, $matches) === 1) {
                return trim($matches[0]);
            }
        }

        return null;
    }

    /**
     * Lowercase the copy and take the markup out of it.
     *
     * Bodies arrive as HTML from the rich-text editor, so the words a reader
     * sees as adjacent can be separated in the source by a tag or by a
     * non-breaking space. Both would otherwise widen the gap the patterns
     * allow and, on the phrase list, break a phrase in two.
     */
    private static function normalise(string $copy): string
    {
        $text = html_entity_decode(strip_tags($copy), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00a0}", ' ', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;

        return mb_strtolower($text);
    }
}
