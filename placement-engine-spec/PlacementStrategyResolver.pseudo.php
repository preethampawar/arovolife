<?php
declare(strict_types=1);

/**
 * Pseudocode reference for App\Modules\Genealogy\Services\PlacementStrategyResolver.
 *
 * Translate to a real Laravel service during /bootstrap-laravel.
 * Conventions: see docs/architecture/service-layer.md and CLAUDE.md.
 */

final class PlacementStrategyResolver
{
    public function __construct(
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * Resolve which leg ('L' or 'R') should be used and how the choice was classified.
     *
     * @param ?string $sideOpt 'L'|'R'|null — only meaningful when strategy = 'custom' or override scenario.
     * @return array{0:string,1:string} [chosenBy, side] where chosenBy ∈ {admin_default, sponsor_override, prospect_custom}
     *
     * Throws SideRequiredError, SideOverrideForbiddenError.
     */
    public function resolve(string $strategy, ?string $sideOpt, bool $allowSponsorOverride): array
    {
        if ($strategy === 'custom') {
            if ($sideOpt === null) {
                throw new SideRequiredError(
                    'Placement Strategy is "custom"; the sponsor (or prospect) must choose L or R.'
                );
            }
            assert(in_array($sideOpt, ['L', 'R'], true));
            return ['prospect_custom', $sideOpt];
        }

        $default = match ($strategy) {
            'default_left'  => 'L',
            'default_right' => 'R',
        };

        if ($sideOpt !== null && $sideOpt !== $default) {
            if (!$allowSponsorOverride) {
                throw new SideOverrideForbiddenError(
                    'allow_sponsor_override is false; the sponsor cannot override the company default.'
                );
            }
            return ['sponsor_override', $sideOpt];
        }

        return ['admin_default', $default];
    }
}
