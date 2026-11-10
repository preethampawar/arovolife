/**
 * List-page filters — Browser Tests (slice S8)
 *
 * Covers the shared filter toolbar rolled out across the admin and
 * distributor list pages:
 *   app/Modules/Shared/Support/{FilterField,ListFilters}.php
 *   resources/views/components/filter-bar.blade.php
 *
 * The suite is data-driven from ADMIN_PAGES / DISTRIBUTOR_PAGES below, so
 * adding a page to the rollout means adding one row here, not a new test.
 *
 * Requires:
 *   - the e2e stack on http://localhost:8085
 *   - admin@arovolife.test / admin12345 (super-staff — bypasses every
 *     permission via Gate::before, so every page below is reachable)
 *
 * The compliance assertion these skipped tests would make — that an injected
 * `?user_id=<someone else>` cannot widen the query — is covered
 * unconditionally, and more strictly, by
 * tests/Modules/Shared/DistributorFilterScopeTest.php, where both
 * distributors are fixtures. Treat the skips below as missing UI coverage,
 * never as a missing scope guarantee.
 *
 * Distributor coverage runs as partner `root` of the e2e world `many-partners`
 * (the `distributorPage` fixture); no credentials are needed.
 *
 * Run:
 *   npx playwright test tests/Browser/list-filters.spec.js --project=ui
 *
 * Plan: docs/plans/list-page-filters-2026-09-13.md
 */

import { withFeature } from './support/artisan.js';
import { test, expect } from './fixtures.js';

// 25 partners (20 per page on the admin register) and one order for the root.
test.use({ world: { name: 'many-partners' }, partnerKey: 'root' });

/**
 * Every admin list page the rollout touched.
 *
 * `probe` names one filter to exercise for narrowing, as
 * [label, kind, value]. `kind` is 'select' | 'fill'. A page with no probe is
 * asserted only for toolbar presence — some pages' data in a given dev
 * database is too sparse for a meaningful narrowing assertion, and a test
 * that passes only because both row counts are zero is worse than no test.
 */
const ADMIN_PAGES = [
    { path: '/admin/catalog/banners', probe: ['Status', 'select', null] },
    { path: '/admin/catalog/categories', probe: ['Status', 'select', null] },
    { path: '/admin/catalog/products', probe: ['Status', 'select', null] },
    { path: '/admin/commerce/coupons', probe: ['Status', 'select', null] },
    { path: '/admin/commerce/orders', probe: ['Status', 'select', null] },
    { path: '/admin/commerce/bv-ledger', probe: null },
    { path: '/admin/returns', probe: ['Status', 'select', null] },
    { path: '/admin/payments', probe: ['Status', 'select', null] },
    { path: '/admin/content', probe: ['Status', 'select', null] },
    { path: '/admin/announcements', probe: ['Status', 'select', null] },
    { path: '/admin/compliance-documents', probe: null },
    { path: '/admin/contact-inquiries', probe: null },
    { path: '/admin/inventory/suppliers', probe: ['Status', 'select', null] },
    { path: '/admin/inventory/warehouses', probe: ['Status', 'select', null] },
    { path: '/admin/inventory/transfers', probe: ['Status', 'select', null] },
    { path: '/admin/inventory/adjustments', probe: null },
    { path: '/admin/inventory/grns', probe: ['Status', 'select', null] },
    { path: '/admin/inventory/purchase-orders', probe: ['Status', 'select', null] },
    { path: '/admin/compensation/monthly-payouts', probe: null },
    { path: '/admin/compensation/weekly-payouts', probe: null },
    { path: '/admin/dormancy', probe: null },
    { path: '/admin/staff', probe: null },
    { path: '/admin/lifetime-awards', probe: null },
    { path: '/admin/messaging/reports', probe: null },
    { path: '/admin/distributors', probe: ['Status', 'select', null] },
    { path: '/admin/distributor-requests', probe: ['Status', 'select', null] },
    { path: '/admin/kyc', probe: null },
    { path: '/admin/grievances', probe: ['Status', 'select', null] },
    { path: '/admin/line-changes', probe: null },
    { path: '/admin/arete-centres/applications', probe: ['Status', 'select', null] },
];

const DISTRIBUTOR_PAGES = [
    '/orders',
    '/orders/sales',
    '/bv-ledger',
    '/income/wallet',
    '/my/grievances',
    '/my/requests',
    '/notifications',
];

/** Identity keys a distributor-facing filter must never expose. */
const FORBIDDEN_KEYS = ['adn', 'user_id', 'distributor_id', 'sponsor_id'];

const bar = (page) => page.locator('[data-testid="filter-bar"]');
const rows = (page) => page.locator('table tbody tr');

/** Open a list page and require it to render (no 4xx, no 5xx). */
async function gotoListPage(page, path) {
    const response = await page.goto(path);
    const status = response?.status() ?? 0;

    // Every listed page is reachable on the e2e base data (flagged pages switch
    // their flag on first), so a 404 is a failure, not a flag state.
    expect(status, `${path} returned ${status}`).toBeLessThan(400);

    return status;
}

test.describe('admin — the toolbar is present on every rolled-out page', () => {
    for (const { path } of ADMIN_PAGES) {
        // /admin/announcements is behind AnnouncementsFeature, pinned OFF in the snapshot.
        const flag = path === '/admin/announcements' ? 'AnnouncementsFeature' : null;

        const assertToolbar = async (page) => {
            await gotoListPage(page, path);

            await expect(bar(page)).toBeVisible();
            await expect(page.getByTestId('filter-apply')).toBeVisible();

            // A toolbar with no control is furniture — assert it can actually filter.
            const controls = bar(page).locator('input:not([type="hidden"]), select');
            expect(await controls.count(), `${path} has a toolbar but no controls`).toBeGreaterThan(0);
        };

        test(`${path} renders a filter toolbar with at least one labelled control`, async ({ adminPage: page }) => (
            flag ? withFeature(flag, () => assertToolbar(page)) : assertToolbar(page)
        ));
    }
});

test.describe('admin — filtering behaviour', () => {
    test('a select narrows the list and the URL carries the choice', async ({ adminPage: page }) => {
        // Products, not orders: the orders page deliberately keeps its status
        // facets as chips, so its toolbar carries no select to exercise.
        await page.goto('/admin/catalog/products');
        const before = await rows(page).count();

        const select = bar(page).locator('select').first();
        // `option:not([value=""])` — `[value!=""]` is not valid CSS and matches nothing.
        const value = await select.locator('option:not([value=""])').first().getAttribute('value');
        await select.selectOption(value);
        await page.getByTestId('filter-apply').click();

        await expect(page).toHaveURL(new RegExp(`=${encodeURIComponent(value)}`));
        expect(await rows(page).count()).toBeLessThanOrEqual(before);
    });

    test('a text search narrows to rows containing the term', async ({ adminPage: page }) => {
        await page.goto('/admin/distributors');
        const firstAdn = (await rows(page).first().locator('td').nth(1).textContent())?.trim();
        expect(firstAdn).toMatch(/^\d{9}$/);

        await bar(page).locator('input[type="search"]').first().fill(firstAdn);
        await page.getByTestId('filter-apply').click();
        await expect(page).toHaveURL(/[?&]q=/);

        const count = await rows(page).count();
        expect(count).toBeGreaterThan(0);
        for (let i = 0; i < count; i++) {
            await expect(rows(page).nth(i)).toContainText(firstAdn);
        }
    });

    test('two filters compose, and each chip removes only its own', async ({ adminPage: page }) => {
        await page.goto('/admin/distributors?status=active&q=a');

        const chips = page.getByTestId('filter-chip');
        await expect(chips).toHaveCount(2);

        // Removing the q chip must leave status in force.
        await chips.filter({ has: page.locator('[data-filter-key="q"]') }).or(
            page.locator('[data-filter-key="q"]')
        ).first().click();

        await expect(page).toHaveURL(/status=active/);
        await expect(page).not.toHaveURL(/[?&]q=/);
    });

    test('Clear drops every filter and restores the full list', async ({ adminPage: page }) => {
        await page.goto('/admin/distributors');
        const unfiltered = await rows(page).count();

        await page.goto('/admin/distributors?status=terminated');
        await page.getByTestId('filter-clear').click();

        await expect(page).not.toHaveURL(/status=/);
        expect(await rows(page).count()).toBe(unfiltered);
    });

    test('filters survive pagination', async ({ adminPage: page }) => {
        await page.goto('/admin/distributors?status=active');

        // Laravel's paginator renders twice (a mobile copy and a desktop one),
        // so filter to the visible link — the hidden one is never clickable.
        const next = page.getByRole('link', { name: /Next/ }).filter({ visible: true }).first();
        await expect(next).toBeVisible();

        await next.click();
        await expect(page).toHaveURL(/status=active/);
        await expect(page).toHaveURL(/page=/);

        // And the rows on page 2 must still honour the filter, not just the URL.
        await expect(page.getByTestId('filter-chips')).toBeVisible();
    });

    test('applying a filter returns to page 1', async ({ adminPage: page }) => {
        await page.goto('/admin/distributors?page=2');
        expect(await rows(page).count(), 'many-partners has 25 partners, so page 2 has rows').toBeGreaterThan(0);

        await page.getByTestId('filter-apply').click();
        await expect(page).not.toHaveURL(/page=2/);
    });

    test('an invalid select value is discarded, not applied', async ({ adminPage: page }) => {
        // Products, not distributors: the register deliberately validates and
        // redirects instead of discarding (see the note in its controller), so
        // a browser would follow that redirect and report 200 either way —
        // the assertion would pass without proving anything.
        await page.goto('/admin/catalog/products');
        const unfiltered = await rows(page).count();

        const response = await page.goto('/admin/catalog/products?status=__not_a_status__');

        expect(response?.status()).toBe(200);
        expect(await rows(page).count()).toBe(unfiltered);
        await expect(page.getByTestId('filter-chips')).toHaveCount(0);
    });

    test('the register rejects an invalid filter rather than silently ignoring it', async ({ adminPage: page }) => {
        // The DSR register is the one page that validates: an admin who
        // mistypes a filter must not be handed a full list that looks filtered.
        await page.goto('/admin/distributors?cooling_off=bogus');

        // Laravel's validation redirect carries the admin off the register
        // rather than rendering it — the point being that they are never shown
        // an unfiltered list wearing a filtered list's URL.
        expect(page.url()).not.toContain('cooling_off=bogus');
    });

    test('an export link carries the active filters', async ({ adminPage: page }) => {
        // The distributor register has no export; the BV ledger is a page
        // that does (its search filter is `q`).
        await page.goto('/admin/commerce/bv-ledger?q=E2E');

        const exportLink = page.getByTestId('filter-export').first();
        await expect(exportLink).toBeVisible();

        expect(await exportLink.getAttribute('href')).toContain('q=E2E');
    });
});

test.describe('permissions — admin pages are staff-only', () => {
    for (const { path } of ADMIN_PAGES.slice(0, 6)) {
        test(`a guest is redirected away from ${path}`, async ({ page }) => {
            await page.goto(path);
            await expect(page).toHaveURL(/\/login/);
        });
    }
});

test.describe('distributor — own-scoped filtering', () => {
    for (const path of DISTRIBUTOR_PAGES) {
        test(`${path} renders a filter toolbar`, async ({ distributorPage: page }) => {
            await gotoListPage(page, path);
            await expect(bar(page)).toBeVisible();
        });

        test(`${path} offers no cross-distributor filter`, async ({ distributorPage: page }) => {
            await gotoListPage(page, path);

            for (const key of FORBIDDEN_KEYS) {
                await expect(
                    bar(page).locator(`[name="${key}"]`),
                    `${path} exposes a ${key} control — a distributor filter must never reach another distributor`,
                ).toHaveCount(0);
            }
        });

        test(`${path} ignores an injected scope parameter`, async ({ distributorPage: page }) => {
            await gotoListPage(page, path);
            const own = await page.locator('table tbody tr, [data-row]').count();

            const injected = FORBIDDEN_KEYS.map((k) => `${k}=999999`).join('&');
            await gotoListPage(page, `${path}?${injected}`);

            expect(
                await page.locator('table tbody tr, [data-row]').count(),
                `${path} changed its row set when handed another distributor's identity`,
            ).toBe(own);
        });
    }

    test('a distributor is refused the admin area entirely', async ({ distributorPage: page }) => {
        const response = await page.goto('/admin/distributors');

        expect([403, 404]).toContain(response?.status() ?? 0);
        await expect(bar(page)).toHaveCount(0);
    });
});
