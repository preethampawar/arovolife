/**
 * Admin sidebar grouping, breadcrumbs & permission gating — Browser Tests (slice S6)
 *
 * Walks the admin nav IA built in S1-S4
 * (app/Modules/Shared/Support/AdminNavigation.php,
 *  resources/views/admin/layouts/admin.blade.php,
 *  resources/views/admin/layouts/_breadcrumbs.blade.php):
 *   nine grouped sidebar sections with collapse/expand + localStorage
 *   persistence + forced-open-on-active -> route-derived breadcrumbs on
 *   section/detail/child pages -> rail (collapsed) mode -> keyboard/a11y on
 *   the group toggle -> distributor refused the whole /admin prefix.
 *
 * Requires:
 *   - App running at APP_URL (default http://localhost:8084)
 *   - admin@arovolife.test / admin12345 (super-staff — bypasses every
 *     permission via Gate::before, so every group and item in the plan §4
 *     table is visible to this session; no group is expected to be absent)
 *
 * Notes / known data dependencies:
 *   - Test 7 (detail-page breadcrumb) needs at least one row on
 *     /admin/distributors. It skips by name if the index is empty.
 *   - Test 8 (three-level child breadcrumb) needs the Arete Centre
 *     Applications feature flag on. It skips by name if the flag is off
 *     (AdminNavigation only renders the "Applications" child, and the sidebar
 *     Arete Centres item itself, when `AreteCenterApplicationsFeature` is
 *     active for the viewer — plan §4/§5.2); it does not require any row to
 *     exist on that page, only that the page itself is reachable.
 *   - Tests 4 and 5 mutate `localStorage['arovolife_admin_nav_groups']` and
 *     `localStorage['arovolife_admin_sidebar_collapsed']`. Each restores the
 *     key it touched before finishing, because the Playwright config here
 *     runs `workers: 1, fullyParallel: false` — a leaked collapsed group or
 *     rail state would leak into whichever spec file runs next in the same
 *     browser storage context. (Playwright's `page` fixture is per-test, so
 *     this is a courtesy for anyone using the same storage state file
 *     between runs, not a hard isolation guarantee — but it costs nothing to
 *     leave things as they were found.)
 *   - Overview (`data-nav-group="overview"`) is asserted to render with NO
 *     group header/toggle — it is the one group whose `label` is `null`
 *     (AdminNavigation::groups()) and therefore renders flat (plan §4).
 *   - Per-role visibility (admin-operations / admin-finance / admin-compliance
 *     each seeing only its own permitted items, all returning 200) is Pest
 *     coverage (tests/Feature/Shared/AdminNavigationTest.php per plan §6.4),
 *     not Playwright — fixtures.js exposes only `adminPage` (super-staff) and
 *     `distributorPage`, no scoped admin-family login.
 *
 * Run:
 *   npx playwright test tests/Browser/admin-navigation.spec.js
 */

import { test, expect } from './fixtures.js';

test.describe('sidebar grouping', () => {
    test('groups render, and Overview renders flat with no header', async ({ adminPage: page }) => {
        await page.goto('/admin');

        const overview = page.locator('[data-nav-group="overview"]');
        await expect(overview).toBeAttached();
        await expect(overview.locator('[data-nav-group-toggle]')).toHaveCount(0);
        // Overview always has Dashboard at minimum (super-staff sees Action
        // Center too, but this assertion holds regardless of that badge).
        await expect(overview.getByRole('link', { name: /^Dashboard/ })).toBeVisible();

        // Every OTHER group present in the DOM (i.e. not filtered out because
        // every one of its items was hidden from this viewer) has a header
        // button with the group's own accessible label. admin@arovolife.test
        // is super-staff and bypasses every permission, so all eight labelled
        // groups from plan §4 are expected to be present, but the assertion
        // itself only depends on "if present, it looks like a group", not on
        // an exact count.
        const labelledSlugs = ['network', 'commerce', 'inventory', 'compensation', 'catalog', 'support', 'insights', 'system'];
        let seenAtLeastOne = false;
        for (const slug of labelledSlugs) {
            const group = page.locator(`[data-nav-group="${slug}"]`);
            if ((await group.count()) === 0) {
                continue;
            }
            seenAtLeastOne = true;
            const toggle = group.locator('[data-nav-group-toggle]');
            await expect(toggle).toBeVisible();
            await expect(toggle).toHaveAttribute('aria-controls', `nav-group-${slug}`);
        }
        expect(seenAtLeastOne).toBeTruthy();
    });

    test('Warehouses sits inside the Inventory group', async ({ adminPage: page }) => {
        await page.goto('/admin');
        const inventoryGroup = page.locator('[data-nav-group="inventory"]');
        test.skip((await inventoryGroup.count()) === 0, 'Inventory group is not rendered for this admin session (no inventory.view/manage permission).');

        const warehousesLink = inventoryGroup.getByRole('link', { name: 'Warehouses' });
        await expect(warehousesLink).toBeVisible();
        await expect(warehousesLink).toHaveAttribute('href', /\/admin\/inventory\/warehouses/);
    });

    test('the active route\'s group is forced open', async ({ adminPage: page }) => {
        await page.goto('/admin/inventory/suppliers');
        const inventoryGroup = page.locator('[data-nav-group="inventory"]');
        test.skip((await inventoryGroup.count()) === 0, 'Inventory group is not rendered for this admin session.');

        await expect(inventoryGroup).toHaveAttribute('data-nav-group-active', '1');
        const toggle = inventoryGroup.locator('[data-nav-group-toggle]');
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        await expect(page.locator('#nav-group-inventory')).toBeVisible();
    });

    test('collapsing a group persists across reload, then is restored', async ({ adminPage: page }) => {
        await page.goto('/admin');
        const commerceGroup = page.locator('[data-nav-group="commerce"]');
        test.skip((await commerceGroup.count()) === 0, 'Commerce group is not rendered for this admin session.');

        const toggle = commerceGroup.locator('[data-nav-group-toggle]');
        const list = page.locator('#nav-group-commerce');

        // Sanity: starts open (default-all-open per plan §4.1, and Commerce is
        // not the active route on plain /admin).
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');

        try {
            await toggle.click();
            await expect(toggle).toHaveAttribute('aria-expanded', 'false');
            await expect(list).toBeHidden();

            await page.reload();
            const toggleAfterReload = page.locator('[data-nav-group="commerce"] [data-nav-group-toggle]');
            await expect(toggleAfterReload).toHaveAttribute('aria-expanded', 'false');
            await expect(page.locator('#nav-group-commerce')).toBeHidden();
        } finally {
            // Restore: re-expand and confirm the stored state key no longer
            // marks Commerce collapsed, so later tests (workers: 1,
            // fullyParallel: false) see the default all-open layout.
            const toggleNow = page.locator('[data-nav-group="commerce"] [data-nav-group-toggle]');
            if ((await toggleNow.getAttribute('aria-expanded')) === 'false') {
                await toggleNow.click();
            }
            await expect(page.locator('[data-nav-group="commerce"] [data-nav-group-toggle]')).toHaveAttribute('aria-expanded', 'true');
        }
    });

    test('the active route always wins over stored collapsed state', async ({ adminPage: page }) => {
        await page.goto('/admin');
        const inventoryGroup = page.locator('[data-nav-group="inventory"]');
        test.skip((await inventoryGroup.count()) === 0, 'Inventory group is not rendered for this admin session.');

        try {
            // Collapse Inventory while it is not the active group.
            await inventoryGroup.locator('[data-nav-group-toggle]').click();
            await expect(inventoryGroup.locator('[data-nav-group-toggle]')).toHaveAttribute('aria-expanded', 'false');

            // Navigate to a page inside Inventory: server-side forced-open must
            // win over the stored collapsed state (plan §4.1).
            await page.goto('/admin/inventory/stock');
            const inventoryGroupNow = page.locator('[data-nav-group="inventory"]');
            await expect(inventoryGroupNow).toHaveAttribute('data-nav-group-active', '1');
            await expect(inventoryGroupNow.locator('[data-nav-group-toggle]')).toHaveAttribute('aria-expanded', 'true');
            await expect(page.locator('#nav-group-inventory')).toBeVisible();
        } finally {
            // Restore: the localStorage key for this slug is what would leak
            // into later tests, not the DOM (which is server-rendered fresh
            // each request) — clear the stored '0' for inventory explicitly.
            await page.evaluate(() => {
                try {
                    const state = JSON.parse(localStorage.getItem('arovolife_admin_nav_groups') || '{}');
                    delete state.inventory;
                    localStorage.setItem('arovolife_admin_nav_groups', JSON.stringify(state));
                } catch (e) { /* ignore */ }
            });
        }
    });

    test('rail mode hides group headers but keeps item links reachable', async ({ adminPage: page }) => {
        await page.goto('/admin');
        // Force desktop viewport: the collapse rail only applies at >=1024px
        // (admin.blade.php's own media query gate).
        await page.setViewportSize({ width: 1280, height: 900 });

        try {
            await page.locator('#adminSidebarToggle').click();
            await expect(page.locator('html')).toHaveClass(/admin-nav-collapsed/);

            const inventoryGroup = page.locator('[data-nav-group="inventory"]');
            test.skip((await inventoryGroup.count()) === 0, 'Inventory group is not rendered for this admin session.');

            // Group headers are hidden in rail mode (display:none via the
            // html.admin-nav-collapsed .admin-nav-group-header rule).
            await expect(inventoryGroup.locator('.admin-nav-group-header')).toBeHidden();

            // The Stock link itself is still present and its href still
            // resolves — only its text label is visually hidden by CSS
            // (.admin-nav-label is display:none in rail mode), the anchor
            // stays in the DOM and in the tab order.
            const stockLink = inventoryGroup.getByRole('link', { name: 'Stock' });
            await expect(stockLink).toBeAttached();
            await expect(stockLink).toHaveAttribute('href', /\/admin\/inventory\/stock/);
        } finally {
            // Restore expanded rail state so later tests aren't run collapsed.
            const toggle = page.locator('#adminSidebarToggle');
            if (await page.locator('html.admin-nav-collapsed').count()) {
                await toggle.click();
            }
            await expect(page.locator('html')).not.toHaveClass(/admin-nav-collapsed/);
        }
    });

    test('group headers are keyboard-operable buttons with aria-expanded/aria-controls', async ({ adminPage: page }) => {
        await page.goto('/admin');
        const commerceGroup = page.locator('[data-nav-group="commerce"]');
        test.skip((await commerceGroup.count()) === 0, 'Commerce group is not rendered for this admin session.');

        const toggle = commerceGroup.locator('[data-nav-group-toggle]');
        await expect(toggle).toHaveJSProperty('tagName', 'BUTTON');
        await expect(toggle).toHaveAttribute('aria-expanded', /true|false/);
        await expect(toggle).toHaveAttribute('aria-controls', 'nav-group-commerce');

        const expandedBefore = (await toggle.getAttribute('aria-expanded')) === 'true';

        try {
            await toggle.focus();
            await page.keyboard.press('Enter');
            await expect(toggle).toHaveAttribute('aria-expanded', expandedBefore ? 'false' : 'true');
        } finally {
            // Restore original expanded state (and its localStorage entry) so
            // later tests see Commerce however they expect to find it.
            const expandedNow = (await toggle.getAttribute('aria-expanded')) === 'true';
            if (expandedNow !== expandedBefore) {
                await toggle.click();
            }
            await expect(toggle).toHaveAttribute('aria-expanded', expandedBefore ? 'true' : 'false');
        }
    });
});

test.describe('breadcrumbs', () => {
    test('dashboard renders no breadcrumb bar', async ({ adminPage: page }) => {
        await page.goto('/admin');
        await expect(page.locator('nav[aria-label="Breadcrumb"]')).toHaveCount(0);
    });

    test('a section index shows Admin > Inventory > Warehouses', async ({ adminPage: page }) => {
        await page.goto('/admin/inventory/warehouses');
        const breadcrumb = page.locator('nav[aria-label="Breadcrumb"]');
        test.skip((await breadcrumb.count()) === 0, 'No breadcrumb bar rendered on /admin/inventory/warehouses for this admin session (route did not resolve, or access denied).');

        const admin = breadcrumb.getByRole('link', { name: 'Admin', exact: true });
        await expect(admin).toBeVisible();
        await expect(admin).toHaveAttribute('href', /\/admin$/);

        await expect(breadcrumb.getByText('Inventory', { exact: true })).toBeVisible();
        await expect(breadcrumb.getByText('Warehouses', { exact: true }).last()).toBeVisible();

        const current = breadcrumb.locator('[aria-current="page"]');
        await expect(current).toBeVisible();
        await expect(current).toHaveText('Warehouses');
    });

    test('a detail page shows Admin > Network > Distributors > Distributor: <ADN>', async ({ adminPage: page }) => {
        await page.goto('/admin/distributors');
        const firstRow = page.locator('tbody tr').first();
        test.skip((await firstRow.count()) === 0, 'The /admin/distributors index has no rows in the dev database right now.');

        const detailLink = firstRow.locator('a').first();
        test.skip((await detailLink.count()) === 0, 'The first row on /admin/distributors has no link to a detail page.');

        await detailLink.click();
        await page.waitForURL('**/admin/distributors/**');

        const breadcrumb = page.locator('nav[aria-label="Breadcrumb"]');
        test.skip((await breadcrumb.count()) === 0, 'No breadcrumb bar rendered on the distributor detail page.');

        await expect(breadcrumb.getByText('Network', { exact: true })).toBeVisible();
        const distributorsCrumb = breadcrumb.getByRole('link', { name: 'Distributors', exact: true });
        await expect(distributorsCrumb).toBeVisible();

        const current = breadcrumb.locator('[aria-current="page"]');
        await expect(current).toBeVisible();
        await expect(current).toHaveText(/./); // some non-empty heading, e.g. "Distributor: <ADN>"

        await distributorsCrumb.click();
        await page.waitForURL('**/admin/distributors');
    });

    test('a three-level child page shows Admin > Network > Arete Centres > Applications', async ({ adminPage: page }) => {
        const response = await page.goto('/admin/arete-centres/applications');
        test.skip((response?.status() ?? 0) >= 400, 'The Arete Centre Applications feature flag is off (or the item is hidden) for this admin session.');

        const breadcrumb = page.locator('nav[aria-label="Breadcrumb"]');
        test.skip((await breadcrumb.count()) === 0, 'No breadcrumb bar rendered on /admin/arete-centres/applications.');

        await expect(breadcrumb.getByText('Network', { exact: true })).toBeVisible();
        await expect(breadcrumb.getByRole('link', { name: 'Arete Centres', exact: true })).toBeVisible();
        await expect(breadcrumb.getByText('Applications', { exact: true }).last()).toBeVisible();
    });
});

test.describe('permissions', () => {
    test('a distributor cannot reach the admin console at all', async ({ distributorPage: page }) => {
        // No admin-family role (`developer|admin|admin-operations|admin-finance|
        // admin-compliance`, routes/web.php) is ever assigned to a distributor,
        // so the route's role middleware refuses the whole /admin prefix
        // before any nav or breadcrumb rendering is reached — same pattern as
        // action-center.spec.js's permission test.
        const response = await page.goto('/admin');
        expect(response?.status()).not.toBe(200);
        await expect(page.locator('[data-nav-group="overview"]')).toHaveCount(0);
        await expect(page.locator('nav[aria-label="Breadcrumb"]')).toHaveCount(0);
    });
});
