<?php

declare(strict_types=1);

namespace App\Modules\Shared\Features;

/**
 * Gates the repurchase / income-eligibility engine. When OFF (default) the GSB
 * cut-off ignores repurchase status entirely, so behaviour is unchanged until a
 * partner enables it from /admin/feature-flags.
 */
final class RepurchaseEngineFeature
{
    public function resolve(mixed $scope): bool
    {
        return false;
    }
}
