<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Models\Franchise;

/**
 * Generates unique, sequential franchise codes in the format ARV-FR-NNNNNN.
 *
 * The sequence is derived from the highest numeric suffix already stored in
 * the franchises table so no separate sequence table is required.
 * Race safety: a unique index on `franchises.code` rejects collisions.
 */
final class FranchiseCodeGenerator
{
    public function generate(): string
    {
        $last = Franchise::query()
            ->where('code', 'like', 'ARV-FR-%')
            ->orderByDesc('id')
            ->value('code');

        $next = 1;

        if ($last !== null && preg_match('/ARV-FR-(\d+)$/', $last, $m)) {
            $next = ((int) $m[1]) + 1;
        }

        return sprintf('ARV-FR-%06d', $next);
    }
}
