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
 * The check is a substring match on the lowercased body, not a regex. The
 * phrases are the ones a real person actually writes, and every attempt to
 * make this cleverer has produced false positives on legitimate copy.
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

    /** The first banned phrase in the given copy, or null if it is clean. */
    public static function firstBannedPhrase(string $copy): ?string
    {
        $haystack = mb_strtolower($copy);

        foreach (self::BANNED_PHRASES as $phrase) {
            if (str_contains($haystack, $phrase)) {
                return $phrase;
            }
        }

        return null;
    }
}
