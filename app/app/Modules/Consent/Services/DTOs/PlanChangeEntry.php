<?php

declare(strict_types=1);

namespace App\Modules\Consent\Services\DTOs;

/**
 * One version's entry under "Changes to this plan, by version" on the
 * published plan page: its label and the HTML of that entry, cut from the
 * page body so the notice and the published text can never say different
 * things.
 */
final class PlanChangeEntry
{
    public function __construct(
        public readonly string $label,
        public readonly string $html,
    ) {}
}
