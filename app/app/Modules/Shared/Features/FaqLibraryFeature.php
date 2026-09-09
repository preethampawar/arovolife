<?php

declare(strict_types=1);

namespace App\Modules\Shared\Features;

/**
 * Gates the FAQ library — the categorised, searchable question-and-answer
 * surface for distributors, and the `faq` content-page type behind it.
 *
 * The library exists so that plan, payout and KYC questions have one audited
 * company answer. Today they are answered by uplines in direct messages,
 * where nothing scans the wording for an income claim. Its entries are
 * ordinary content pages, so they inherit the draft → published workflow and
 * the public copy audit.
 *
 * Default: `false`. While off the feature leaves no trace: no menu item, no
 * routes (404), and `faq` is rejected as a content-page type in the admin
 * editor, so no draft can be authored against a surface nobody can reach.
 *
 * Resolved via:
 *     Feature::active(FaqLibraryFeature::class)
 */
final class FaqLibraryFeature
{
    public function resolve(mixed $scope): bool
    {
        return false;
    }
}
