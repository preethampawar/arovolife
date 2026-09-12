<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\AnnouncementsFeature;
use App\Modules\Shared\Features\DistributorRequestsFeature;
use App\Modules\Shared\Features\MessagingFeature;
use App\Modules\Shared\Features\PurchaseOffersFeature;
use Laravel\Pennant\Feature;

/**
 * Single source of truth for the admin sidebar.
 *
 * The item list used to live as a `$navItems` literal inside
 * `resources/views/admin/layouts/admin.blade.php`, which made it unreadable
 * from anywhere but Blade — so nothing could assert that every link a role is
 * shown is a page that role may actually open.
 *
 * This class is deliberately **pure**: it runs permission and feature-flag
 * checks (cheap, already cached by their own gates) but never a query and
 * never a cache read for a badge *value*. Badge counts stay in the layout's
 * `@php` block, where their 60s caches live, and are handed in via `$badges`
 * keyed by the short slugs documented on {@see self::groups()}.
 *
 * @phpstan-type NavItem array{
 *     route: string,
 *     label: string,
 *     icon: string,
 *     prefix?: string,
 *     params?: array<string, mixed>,
 *     badge?: int,
 *     children?: list<array{prefix: string, label: string, route: string}>,
 * }
 * @phpstan-type NavGroup array{
 *     key: string,
 *     label: string|null,
 *     icon: string|null,
 *     items: list<NavItem>,
 * }
 */
final class AdminNavigation
{
    /**
     * The sidebar, as a list of groups in the documented IA order:
     * what you watch → who is in the network → what they buy → what we hold
     * → what we pay → what we publish → what we answer → what we measure
     * → what we configure.
     *
     * Overview carries a `null` label: it is the landing context and renders
     * flat, with no header and no collapse control. A group whose items are
     * all hidden from this viewer is dropped entirely, so an empty section
     * leaves neither a header nor a divider behind.
     *
     * @param  array<string, int>  $badges  Pre-computed counts keyed by slug:
     *                                      `action-center`, `contact`, `grievances`,
     *                                      `message-reports`, `distributor-requests`,
     *                                      `inventory-reports`, `payments`,
     *                                      `adc-applications`, `engine-failures`.
     * @return list<NavGroup>
     */
    public static function groups(?User $user, array $badges = []): array
    {
        $groups = [
            ['key' => 'overview', 'label' => null, 'icon' => 'layout-dashboard', 'items' => self::overviewItems($user, $badges)],
            ['key' => 'network', 'label' => 'Network', 'icon' => 'users', 'items' => self::networkItems($user, $badges)],
            ['key' => 'commerce', 'label' => 'Commerce', 'icon' => 'shopping-cart', 'items' => self::commerceItems($user, $badges)],
            ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'boxes', 'items' => self::inventoryItems($user, $badges)],
            ['key' => 'compensation', 'label' => 'Compensation', 'icon' => 'banknote', 'items' => self::compensationItems($user, $badges)],
            ['key' => 'catalog', 'label' => 'Catalog & Content', 'icon' => 'package', 'items' => self::catalogItems($user, $badges)],
            ['key' => 'support', 'label' => 'Support & Compliance', 'icon' => 'life-buoy', 'items' => self::supportItems($user, $badges)],
            ['key' => 'insights', 'label' => 'Insights', 'icon' => 'chart-line', 'items' => self::insightsItems($user, $badges)],
            ['key' => 'system', 'label' => 'System', 'icon' => 'settings', 'items' => self::systemItems($user, $badges)],
        ];

        return array_values(array_filter($groups, fn (array $group): bool => $group['items'] !== []));
    }

    /**
     * Resolve a route name to its place in the sidebar, for breadcrumbs.
     *
     * Matching is longest-prefix over every *visible* item's `prefix` (or
     * `route`, when it declares none) and over the `children` each item
     * declares. That covers all named admin routes without a single per-view
     * edit: a compensation report nobody mapped simply resolves to the
     * Compensation item, which is the correct ancestry.
     *
     * Returns `null` for the dashboard (which is the breadcrumb root itself,
     * so it gets no trail) and for anything that matches nothing.
     *
     * @return array{group: array{key: string, label: string|null, icon: string|null}, item: NavItem, child: array{prefix: string, label: string, route: string}|null}|null
     */
    public static function resolve(?string $routeName, ?User $user): ?array
    {
        if ($routeName === null || $routeName === '' || $routeName === 'admin.dashboard') {
            return null;
        }

        $best = null;
        $bestLength = -1;

        foreach (self::groups($user) as $group) {
            $groupHeader = ['key' => $group['key'], 'label' => $group['label'], 'icon' => $group['icon']];

            foreach ($group['items'] as $item) {
                // An item with no explicit prefix still owns its siblings:
                // `admin.distributors.index` must claim `admin.distributors.show`.
                $itemPrefix = $item['prefix']
                    ?? (str_ends_with($item['route'], '.index')
                        ? substr($item['route'], 0, -6)
                        : $item['route']);

                if (self::matches($routeName, $itemPrefix) && strlen($itemPrefix) > $bestLength) {
                    $best = ['group' => $groupHeader, 'item' => $item, 'child' => null];
                    $bestLength = strlen($itemPrefix);
                }

                foreach ($item['children'] ?? [] as $child) {
                    if (self::matches($routeName, $child['prefix']) && strlen($child['prefix']) > $bestLength) {
                        $best = ['group' => $groupHeader, 'item' => $item, 'child' => $child];
                        $bestLength = strlen($child['prefix']);
                    }
                }
            }
        }

        return $best;
    }

    /**
     * A prefix matches a route name when it *is* the route name or is one of
     * its dot-separated ancestors — never a bare string prefix, so
     * `admin.returns` does not swallow `admin.returns-policy.*`.
     */
    private static function matches(string $routeName, string $prefix): bool
    {
        return $routeName === $prefix || str_starts_with($routeName, $prefix.'.');
    }

    /**
     * @param  array<string, int>  $badges
     * @return list<NavItem>
     */
    private static function overviewItems(?User $user, array $badges): array
    {
        // Temporarily dev-only while the screen beds in; explicit product decision 2026-09-12.
        $actionCenterOn = ($user?->can('action.center.view') ?? false)
            && ($user?->hasRole('developer') ?? false);

        return [
            ['route' => 'admin.dashboard',                'label' => 'Dashboard',      'icon' => 'layout-dashboard'],
            ...($actionCenterOn
                ? [['route' => 'admin.action-center.index', 'label' => 'Action Center', 'icon' => 'siren', 'prefix' => 'admin.action-center', 'badge' => $badges['action-center'] ?? 0]]
                : []),
        ];
    }

    /**
     * @param  array<string, int>  $badges
     * @return list<NavItem>
     */
    private static function networkItems(?User $user, array $badges): array
    {
        $distributorRequestsOn = Feature::for(null)->active(DistributorRequestsFeature::class)
            && ($user?->can('distributor.request.handle') ?? false);

        return [
            ['route' => 'admin.distributors.index',       'label' => 'Distributors',   'icon' => 'users'],
            ['route' => 'admin.tree.show',                'label' => 'Genealogy tree', 'icon' => 'network', 'prefix' => 'admin.tree'],
            // KYC is gated on `kyc.review` (R-17). Hiding the item
            // rather than letting it 403 keeps admin-finance from
            // walking into a wall on every shift.
            ...($user?->can('kyc.review')
                ? [['route' => 'admin.kyc.index',            'label' => 'KYC review',     'icon' => 'file-check', 'prefix' => 'admin.kyc']]
                : []),
            ['route' => 'admin.line-changes.index',       'label' => 'Line changes',   'icon' => 'arrow-right-left', 'prefix' => 'admin.line-changes'],
            ...($distributorRequestsOn
                ? [['route' => 'admin.distributor-requests.index', 'label' => 'Distributor requests', 'icon' => 'clipboard-list', 'prefix' => 'admin.distributor-requests', 'badge' => $badges['distributor-requests'] ?? 0]]
                : []),
            // Agreement §21 dormancy. Account discipline, so it follows
            // the same permission as freeze / terminate.
            ...($user?->can('compliance.discipline')
                ? [['route' => 'admin.dormancy.index', 'label' => 'Dormancy (§21)', 'icon' => 'hourglass', 'prefix' => 'admin.dormancy']]
                : []),
            // Arete Development Centres are entities in their own right
            // (Step 11, profile, member directory); the ADC bonus is a
            // layer on top and lives under Compensation.
            ['route' => 'admin.arete-centres.index',      'label' => 'Arete Centres',  'icon' => 'landmark', 'prefix' => 'admin.arete-centres', 'badge' => $badges['adc-applications'] ?? 0, 'children' => [
                ['prefix' => 'admin.arete-centres.applications', 'label' => 'Applications', 'route' => 'admin.arete-centres.applications.index'],
            ]],
        ];
    }

    /**
     * @param  array<string, int>  $badges
     * @return list<NavItem>
     */
    private static function commerceItems(?User $user, array $badges): array
    {
        return [
            ['route' => 'admin.commerce.orders.index',    'label' => 'Orders',         'icon' => 'shopping-cart', 'prefix' => 'admin.commerce.orders', 'children' => [
                ['prefix' => 'admin.returns', 'label' => 'Returns', 'route' => 'admin.returns.index'],
            ]],
            // Payments and the unsettled-refunds worklist. Monitoring
            // (`audit.read`), so every scoped role sees it; the badge
            // is refunds needing a human — failed, or held past the
            // 10-day return-receipt alert.
            ...($user?->can('audit.read')
                ? [['route' => 'admin.payments.index', 'label' => 'Payments', 'icon' => 'credit-card', 'prefix' => 'admin.payments', 'badge' => $badges['payments'] ?? 0]]
                : []),
            ['route' => 'admin.commerce.coupons.index',   'label' => 'Coupons',        'icon' => 'tag', 'prefix' => 'admin.commerce.coupons'],
            ...(Feature::for(null)->active(PurchaseOffersFeature::class)
                ? [['route' => 'admin.commerce.offers.index', 'label' => 'Offers', 'icon' => 'gift', 'prefix' => 'admin.commerce.offers']]
                : []),
            // The BV ledger route is `can:audit.read`, same as Payments. Every
            // scoped role holds that today, so gating the item changes nothing
            // visible now — it just keeps the item and its route in step.
            ...($user?->can('audit.read')
                ? [['route' => 'admin.commerce.bv-ledger.index', 'label' => 'BV Ledger', 'icon' => 'chart-column', 'prefix' => 'admin.commerce.bv-ledger']]
                : []),
        ];
    }

    /**
     * Inventory: stock, reports, warehouses, suppliers, purchase orders,
     * goods receipts, transfers, adjustments. Stock and Reports are
     * `inventory.view` (also open to admin-finance); everything else moves
     * stock or commits spend and stays behind `inventory.manage`.
     *
     * @param  array<string, int>  $badges
     * @return list<NavItem>
     */
    private static function inventoryItems(?User $user, array $badges): array
    {
        return [
            ...($user?->can('inventory.view')
                ? [
                    ['route' => 'admin.inventory.stock.index', 'label' => 'Stock', 'icon' => 'boxes', 'prefix' => 'admin.inventory.stock'],
                    ['route' => 'admin.inventory.reports.index', 'label' => 'Reports', 'icon' => 'file-bar-chart', 'prefix' => 'admin.inventory.reports', 'badge' => $badges['inventory-reports'] ?? 0],
                ]
                : []),
            ...($user?->can('inventory.manage')
                ? [
                    ['route' => 'admin.inventory.warehouses.index', 'label' => 'Warehouses', 'icon' => 'warehouse', 'prefix' => 'admin.inventory.warehouses'],
                    ['route' => 'admin.inventory.suppliers.index', 'label' => 'Suppliers', 'icon' => 'truck', 'prefix' => 'admin.inventory.suppliers'],
                    ['route' => 'admin.inventory.purchase-orders.index', 'label' => 'Purchase Orders', 'icon' => 'clipboard-list', 'prefix' => 'admin.inventory.purchase-orders'],
                    ['route' => 'admin.inventory.grns.index', 'label' => 'Goods Receipts (GRN)', 'icon' => 'package-check', 'prefix' => 'admin.inventory.grns'],
                    ['route' => 'admin.inventory.transfers.index', 'label' => 'Transfers', 'icon' => 'arrow-left-right', 'prefix' => 'admin.inventory.transfers'],
                    ['route' => 'admin.inventory.adjustments.index', 'label' => 'Adjustments', 'icon' => 'sliders-horizontal', 'prefix' => 'admin.inventory.adjustments'],
                ]
                : []),
        ];
    }

    /**
     * @param  array<string, int>  $badges
     * @return list<NavItem>
     */
    private static function compensationItems(?User $user, array $badges): array
    {
        $failedEngineRunCount = $badges['engine-failures'] ?? 0;

        return [
            ['route' => 'admin.compensation.overview',    'label' => 'Compensation',   'icon' => 'banknote', 'prefix' => 'admin.compensation', 'children' => [
                ['prefix' => 'admin.compensation.engine-runs', 'label' => 'Engine Runs', 'route' => 'admin.compensation.engine-runs.index'],
                ['prefix' => 'admin.compensation.plan-settings', 'label' => 'Plan Settings', 'route' => 'admin.compensation.plan-settings.index'],
                ['prefix' => 'admin.compensation.payout-settings', 'label' => 'Payout Settings', 'route' => 'admin.compensation.payout-settings.index'],
                ['prefix' => 'admin.compensation.weekly-payouts', 'label' => 'Weekly Payouts', 'route' => 'admin.compensation.weekly-payouts.index'],
                ['prefix' => 'admin.compensation.monthly-payouts', 'label' => 'Monthly Payouts', 'route' => 'admin.compensation.monthly-payouts.index'],
                ['prefix' => 'admin.lifetime-awards', 'label' => 'Lifetime Awards', 'route' => 'admin.lifetime-awards.index'],
            ]],
            // Only rendered while something is actually broken, so a
            // healthy console carries no extra item.
            ...($failedEngineRunCount > 0
                ? [['route' => 'admin.compensation.engine-runs.events', 'params' => ['status' => 'failed'], 'label' => 'Engine failures', 'icon' => 'triangle-alert', 'badge' => $failedEngineRunCount]]
                : []),
        ];
    }

    /**
     * @param  array<string, int>  $badges
     * @return list<NavItem>
     */
    private static function catalogItems(?User $user, array $badges): array
    {
        return [
            ['route' => 'admin.catalog.products.index',   'label' => 'Products',       'icon' => 'package', 'prefix' => 'admin.catalog.products'],
            ['route' => 'admin.catalog.categories.index', 'label' => 'Categories',     'icon' => 'folder-tree', 'prefix' => 'admin.catalog.categories'],
            ['route' => 'admin.catalog.banners.index',    'label' => 'Banners',        'icon' => 'image', 'prefix' => 'admin.catalog.banners'],
            // Both Content Pages and Announcements are gated `can:content.publish`
            // on their routes (R-17 excludes admin-finance). Hiding them rather
            // than letting them 403 is the §6.1 fix: a link a role cannot open
            // must not be rendered for that role.
            ...($user?->can('content.publish')
                ? [
                    ['route' => 'admin.content.index', 'label' => 'Content Pages', 'icon' => 'file-text', 'prefix' => 'admin.content'],
                    ...(Feature::for(null)->active(AnnouncementsFeature::class)
                        ? [['route' => 'admin.announcements.index', 'label' => 'Announcements', 'icon' => 'megaphone', 'prefix' => 'admin.announcements']]
                        : []),
                ]
                : []),
        ];
    }

    /**
     * @param  array<string, int>  $badges
     * @return list<NavItem>
     */
    private static function supportItems(?User $user, array $badges): array
    {
        $messagingOn = Feature::for(null)->active(MessagingFeature::class);

        return [
            ['route' => 'admin.contact-inquiries.index',  'label' => 'Contact Inbox',  'icon' => 'mail', 'prefix' => 'admin.contact-inquiries', 'badge' => $badges['contact'] ?? 0],
            // Grievances are gated on `grievance.handle` (R-17: not
            // admin-finance). Hiding the item rather than letting it
            // 403 also keeps the open-complaint count out of view.
            ...($user?->can('grievance.handle')
                ? [['route' => 'admin.grievances.index', 'label' => 'Grievances', 'icon' => 'megaphone', 'prefix' => 'admin.grievances', 'badge' => $badges['grievances'] ?? 0]]
                : []),
            // Reported messages. Same R-17 exclusion as grievances, and
            // hidden rather than 403 for the same reason: the count of
            // open reports is itself information.
            ...($user?->can('messaging.moderate') && $messagingOn
                ? [['route' => 'admin.messaging.reports.index', 'label' => 'Reported messages', 'icon' => 'message-square-warning', 'prefix' => 'admin.messaging', 'badge' => $badges['message-reports'] ?? 0]]
                : []),
            ['route' => 'admin.compliance-documents.index', 'label' => 'Compliance Docs', 'icon' => 'shield-check', 'prefix' => 'admin.compliance-documents'],
        ];
    }

    /**
     * @param  array<string, int>  $badges
     * @return list<NavItem>
     */
    private static function insightsItems(?User $user, array $badges): array
    {
        return [
            ...($user?->can('audit.read')
                ? [
                    ['route' => 'admin.analytics.index',      'label' => 'Analytics',      'icon' => 'chart-line', 'prefix' => 'admin.analytics'],
                    ['route' => 'admin.audit-log',            'label' => 'Audit Log',      'icon' => 'scroll-text'],
                ]
                : []),
        ];
    }

    /**
     * @param  array<string, int>  $badges
     * @return list<NavItem>
     */
    private static function systemItems(?User $user, array $badges): array
    {
        return [
            // Staff register is super-staff only (route enforces role:admin|developer).
            ...($user?->isSuperStaff()
                ? [['route' => 'admin.staff.index',       'label' => 'Staff users',    'icon' => 'users-round', 'prefix' => 'admin.staff']]
                : []),
            ['route' => 'admin.settings',                 'label' => 'Settings',       'icon' => 'settings'],
            ['route' => 'admin.feature-flags.index',      'label' => 'Feature flags',  'icon' => 'flag', 'prefix' => 'admin.feature-flags'],
            ['route' => 'admin.help.index',               'label' => 'Help & Reference', 'icon' => 'circle-help', 'prefix' => 'admin.help'],
        ];
    }
}
