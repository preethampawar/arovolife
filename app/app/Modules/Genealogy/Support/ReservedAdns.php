<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Support;

use App\Modules\Genealogy\Services\PlacementEngine;

/**
 * The 63 ADNs permanently reserved for arovolife Private Limited's
 * company-blocked binary tree: the root plus five full levels below it
 * (1 + 2 + 4 + 8 + 16 + 32), tree depths 0-5.
 *
 * These values are intentionally hard-coded so that `php artisan platform:reset`
 * is fully deterministic: every reset rebuilds the exact same reserved block,
 * which makes the company-blocked nodes auditable, linkable from external
 * docs, and stable across environments.
 *
 * The first 30 non-root values (depths 1-4) were generated once with
 * `mt_srand(20260519)` against `mt_rand(100000001, 999999999)`. The 32
 * depth-5 values were added 2026-09-25 with `mt_srand(20260925)` against the
 * same range, skipping any value already in the list. Both seeds are recorded
 * so the list can be reproduced if it is ever lost.
 *
 * Organic distributor ADNs are minted by {@see PlacementEngine::generateAdn()}
 * which skips this set to guarantee uniqueness without relying solely on the
 * `uniq_distributors_adn` index.
 */
final class ReservedAdns
{
    /** Root L0 company node. Permanently reserved, never re-issued. */
    public const ROOT = '444555666';

    /**
     * 62 fixed ADNs for the five-level binary subtree under the root, in
     * breadth-first order. Index 0 = left child of root, index 1 = right
     * child, indices 2..5 = the next level (L of L, R of L, L of R, R of R),
     * and so on; indices 30..61 are depth 5. Append-only: an index is a tree
     * position, so reordering would move a company account in the tree.
     *
     * @var list<string>
     */
    public const CHILDREN = [
        '973708897', '177536419', '957327353', '608628172', '920536893',
        '946362630', '726919720', '282859080', '329053434', '248958325',
        '997517873', '943689589', '426583368', '661965316', '854485739',
        '954454971', '332524132', '191449618', '916574415', '976022960',
        '650281627', '933707676', '508132879', '720072702', '713382955',
        '154693425', '390869411', '506784834', '900358379', '231868957',
        // Depth 5 (added 2026-09-25).
        '599939333', '845868785', '114431490', '163264616', '372287476',
        '718378570', '295580148', '368796994', '675679180', '352851509',
        '779902981', '227736900', '771904602', '754444258', '826790351',
        '665819586', '150283435', '228614232', '877551258', '933942559',
        '525730663', '286436582', '107391632', '506750279', '202496497',
        '490844783', '811859256', '225932215', '224827967', '844762611',
        '722492701', '576780921',
    ];

    /**
     * The 63 reserved ADNs in tree order (root first, then the 62 children
     * in breadth-first order, depths 1-5).
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::ROOT, ...self::CHILDREN];
    }

    /**
     * O(1) membership check via flipped lookup — used by the placement
     * engine on every ADN allocation attempt.
     *
     * @return array<string, true>
     */
    public static function asLookup(): array
    {
        static $lookup = null;
        if ($lookup === null) {
            $lookup = array_fill_keys(self::all(), true);
        }

        return $lookup;
    }

    public static function isReserved(string $adn): bool
    {
        return isset(self::asLookup()[$adn]);
    }
}
