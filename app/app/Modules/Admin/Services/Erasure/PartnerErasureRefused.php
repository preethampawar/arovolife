<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services\Erasure;

use RuntimeException;

/**
 * The erasure did not run and nothing was changed. Carries every reason, in
 * the same shape as the preview's blockers.
 */
final class PartnerErasureRefused extends RuntimeException
{
    /**
     * @param  list<array{code: string, message: string}>  $blockers
     */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct(implode(' ', array_column($blockers, 'message')));
    }
}
