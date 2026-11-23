<?php

declare(strict_types=1);

namespace App\Modules\Shared\Testing\Scenarios;

use Closure;
use InvalidArgumentException;

/**
 * The named, deterministic scenarios `e2e:scenario` can build. Each one states
 * its own dates; the money fixtures in tests/Browser/money/fixtures/ are
 * hand-worked from exactly these inputs, so a change here changes them too.
 *
 * Catalogue BV (base seed): AV-SH-001-V1 = 800 BV, AV-OL-001-V1 = 700 BV,
 * AV-IB-001-V1 = 300 BV.
 */
final class E2eScenarios
{
    /** @return array<string, Closure(E2eScenarioBuilder): void> */
    public static function all(): array
    {
        return [
            // One partner, no orders — for UI specs that only need a login.
            'partner' => static function (E2eScenarioBuilder $b): void {
                $b->partner('solo', joinedAt: '2026-06-01 09:00:00');
            },

            // A root with one partner on each side. The root holds the slab-1
            // title (3,200 BV ≥ 3,000) and each leg buys 15,200 BV on 3 June,
            // so the 3 June cut-off matches slab 1 (15,000 BV a side).
            'gsb-basic' => static function (E2eScenarioBuilder $b): void {
                $b->partner('root', joinedAt: '2026-06-01 09:00:00')
                    ->partner('left', 'root', 'left', '2026-06-01 09:10:00')
                    ->partner('right', 'root', 'right', '2026-06-01 09:20:00')
                    ->order('root-title', 'root', '2026-06-02 10:00:00', [['AV-SH-001-V1', 4]])
                    ->order('left-buy', 'left', '2026-06-03 11:00:00', [['AV-SH-001-V1', 19]])
                    ->order('right-buy', 'right', '2026-06-03 12:00:00', [['AV-SH-001-V1', 19]]);
                self::staff($b);
            },

            // Partner-erasure spec: root has 'target' on the left and 'outsider'
            // on the right; the target has one partner on each side. No orders.
            'erasure-tree' => static function (E2eScenarioBuilder $b): void {
                $b->partner('root', joinedAt: '2026-06-01 09:00:00')
                    ->partner('target', 'root', 'left', '2026-06-01 09:10:00')
                    ->partner('outsider', 'root', 'right', '2026-06-01 09:20:00')
                    ->partner('tleft', 'target', 'left', '2026-06-01 09:30:00')
                    ->partner('tright', 'target', 'right', '2026-06-01 09:40:00');
                self::staff($b);
                $b->staff('plainadmin', 'admin');
            },

            // 25 partners in a balanced Genos under 'root' (keys root, p1 … p24:
            // p{n} sits under p{(n-1) div 2}, odd n left, even n right) plus one
            // paid order for the root — enough for the 20-a-page admin lists to
            // have a second page. No engines are needed.
            'many-partners' => static function (E2eScenarioBuilder $b): void {
                $b->partner('root', joinedAt: '2026-06-01 09:00:00');

                for ($n = 1; $n <= 24; $n++) {
                    $parent = intdiv($n - 1, 2) === 0 ? 'root' : 'p'.intdiv($n - 1, 2);
                    $b->partner('p'.$n, $parent, $n % 2 === 1 ? 'left' : 'right', '2026-06-01 10:'.str_pad((string) $n, 2, '0', STR_PAD_LEFT).':00');
                }

                $b->order('root-buy', 'root', '2026-06-02 10:00:00', [['AV-SH-001-V1', 1]]);
                self::staff($b);
            },
        ];
    }

    /**
     * One login per back-office role, for permission specs: finance@e2e.test,
     * operations@e2e.test, compliance@e2e.test, developer@e2e.test. Staff are
     * users only — no distributor rows — so they never touch a money figure.
     */
    private static function staff(E2eScenarioBuilder $b): void
    {
        $b->staff('finance', 'admin-finance')
            ->staff('operations', 'admin-operations')
            ->staff('compliance', 'admin-compliance')
            ->staff('developer', 'developer');
    }

    /** @return Closure(E2eScenarioBuilder): void */
    public static function get(string $name): Closure
    {
        return self::all()[$name]
            ?? throw new InvalidArgumentException("Unknown scenario '{$name}'. Known: ".implode(', ', array_keys(self::all())));
    }
}
