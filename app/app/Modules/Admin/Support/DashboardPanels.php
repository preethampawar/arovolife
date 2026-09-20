<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Admin\Http\Controllers\AdminDashboardPanelController;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\ActionCenterFeature;
use App\Modules\Shared\Features\InventoryFeature;
use Laravel\Pennant\Feature;

/**
 * The dashboard's panel registry: the single list both the shell and the
 * fragment endpoint read.
 *
 * The dashboard renders nothing itself. `/admin` emits one placeholder per
 * panel this viewer may see and every number arrives afterwards from
 * {@see AdminDashboardPanelController}.
 * Two callers therefore need the same answer to "which panels, in what order,
 * gated on what" — the shell to decide what to emit, the endpoint to decide
 * what to serve — and a list that lived in only one of them would let the two
 * disagree about who may see what.
 *
 * `permission` is the ability that already gates the panel's *source* data,
 * never a new dashboard-specific one: a viewer sees a summary exactly when
 * they could open the page it summarises. `feature` is checked here rather
 * than in the view because a flag-off module must leave no trace at all — a
 * placeholder that 404s still tells the reader the module exists.
 *
 * `lazy` false means the panel is fetched on DOMContentLoaded (it is above the
 * fold); true means it waits for the viewer to scroll near it. The const's own
 * order is render order.
 *
 * One card per console section, every one full width. Two panels may share a
 * card only when they share a gate, or when the narrower half can be gated
 * again inside the card: `commerce` carries revenue (`sales.report.view`) over
 * a pipeline every admin may read, and the view asks a second time before it
 * prints a rupee. Half-width cards are gone — their bottoms never lined up,
 * and an odd panel count left a hole beside the last one.
 */
final class DashboardPanels
{
    /**
     * @var array<string, array{title: string, permission: string|null,
     *                          feature: class-string|null, lazy: bool}>
     */
    public const PANELS = [
        'attention' => [
            'title' => 'Needs attention',
            'permission' => 'action.center.view',
            'feature' => ActionCenterFeature::class,
            'lazy' => false,
        ],
        'commerce' => [
            'title' => 'Commerce',
            'permission' => null,
            'feature' => null,
            'lazy' => false,
        ],
        'inventory' => [
            'title' => 'Inventory',
            'permission' => 'inventory.view',
            'feature' => InventoryFeature::class,
            'lazy' => true,
        ],
        'compensation' => [
            'title' => 'Compensation',
            'permission' => 'finance.record',
            'feature' => null,
            'lazy' => true,
        ],
        'people' => [
            'title' => 'Network',
            'permission' => null,
            'feature' => null,
            'lazy' => true,
        ],
    ];

    /**
     * The panels this viewer may see, in render order.
     *
     * Both gates run here, before anything reaches the DOM. Hiding a panel in
     * the browser would still have shipped its placeholder — and with it the
     * fact that the module exists — to someone the flag or the permission was
     * meant to keep it from.
     *
     * @return array<string, array{title: string, permission: string|null,
     *                             feature: class-string|null, lazy: bool}>
     */
    public static function visibleTo(?User $user): array
    {
        $visible = [];

        foreach (self::PANELS as $key => $meta) {
            if ($meta['permission'] !== null && ! ($user?->can($meta['permission']) ?? false)) {
                continue;
            }

            if ($meta['feature'] !== null && ! Feature::for(null)->active($meta['feature'])) {
                continue;
            }

            $visible[$key] = $meta;
        }

        return $visible;
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::PANELS);
    }

    public static function title(string $key): string
    {
        return self::PANELS[$key]['title'] ?? $key;
    }
}
