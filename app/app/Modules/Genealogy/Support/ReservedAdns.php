<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Support;

use App\Modules\Genealogy\Services\PlacementEngine;

/**
 * The 31 ADNs permanently reserved for arovolife Private Limited's
 * company-blocked binary tree (1 root + 5 levels = 1 + 2 + 4 + 8 + 16).
 *
 * These values are intentionally hard-coded so that `php artisan platform:reset`
 * is fully deterministic: every reset rebuilds the exact same reserved block,
 * which makes the company-blocked nodes auditable, linkable from external
 * docs, and stable across environments.
 *
 * The 30 non-root values were generated once with `mt_srand(20260519)` against
 * `mt_rand(100000001, 999999999)` (see commit message). The seed is recorded
 * so the list can be reproduced if it is ever lost.
 *
 * Organic distributor ADNs are minted by {@see PlacementEngine::generateAdn()}
 * which skips this set to guarantee uniqueness without relying solely on the
 * `uniq_distributors_adn` index.
 */
final class ReservedAdns
{
    /** Root L0 company node. Permanently reserved, never re-issued. */
    public const ROOT = '100000000';

    /**
     * 30 fixed ADNs for the 5-level binary subtree under the root.
     * Index 0 = level-2 left child of root, index 1 = level-2 right child,
     * indices 2..5 = level 3 (L of L, R of L, L of R, R of R), and so on.
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
    ];

    /**
     * The 31 reserved ADNs in tree order (root first, then the 30 children
     * in breadth-first traversal of the level-2..level-5 subtree).
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
