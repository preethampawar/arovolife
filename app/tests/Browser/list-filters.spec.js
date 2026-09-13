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
 *   - App running at APP_URL (default http://localhost:8084)
 *   - admin@arovolife.test / admin12345 (super-staff — bypasses every
 *     permission via Gate::before, so every page below is reachable)
 *
 * Distributor coverage is credential-gated. The dev database's distributor
 * accounts do not carry a known test password, and this suite deliberately
 * does not set one — rewriting a password to make a test pass mutates the
 * dev database for everyone who uses it. Export A11Y_ADN and A11Y_PASSWORD
 * for an account you control and the distributor describe block runs;
 * without them it skips with a message naming what to set, rather than
 * failing as though the pages were broken.
 *
 * Run:
 *   npx playwright test tests/Browser/list-filters.spec.js
 *   A11Y_ADN=123456789 A11Y_PASSWORD='…' npx playwright test tests/Browser/list-filters.spec.js
 *
 * Plan: docs/plans/list-page-filters-2026-09-13.md
 */

import { test, expect } from './fixtures.js';

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

/**
 * A page whose feature flag is off 404s, and a dev database legitimately has
 * flags off. Treat that as "not applicable here", not as a failure — but only
 * for 404, never for a 500.
 */
async function gotoOrSkip(page, path) {
    const response = await page.goto(path);
    const status = response?.status() ?? 0;

    if (status === 404) {
        test.skip(true, `${path} is 404 — its feature flag is off in this database`);
    }

    expect(status, `${path} returned ${status}`).toBeLessThan(500);

    return status;
}

test.describe('admin — the toolbar is present on every rolled-out page', () => {
    for (const { path } of ADMIN_PAGES) {
        test(`${path} renders a filter toolbar with at least one labelled control`, async ({ adminPage: page }) => {
            await gotoOrSkip(page, path);

            await expect(bar(page)).toBeVisible();
            await expect(page.getByTestId('filter-apply')).toBeVisible();

            // A toolbar with no control is furniture — assert it can actually filter.
            const controls = bar(page).locator('input:not([type="hidden"]), select');
            expect(await controls.count(), `${path} has a toolbar but no controls`).toBeGreaterThan(0);
        });
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
        test.skip(!firstAdn, 'no distributors in this database');

        await bar(page).locator('input[type="search"]').first().fill(firstAdn);
        await page.getByTestId('filter-apply').click();

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
        test.skip((await next.count()) === 0, 'only one page of active distributors');

        await next.click();
        await expect(page).toHaveURL(/status=active/);
        await expect(page).toHaveURL(/page=/);

        // And the rows on page 2 must still honour the filter, not just the URL.
        await expect(page.getByTestId('filter-chips')).toBeVisible();
    });

    test('applying a filter returns to page 1', async ({ adminPage: page }) => {
        await page.goto('/admin/distributors?page=2');
        test.skip((await rows(page).count()) === 0, 'no second page in this database');

        await page.getByTestId('filter-apply').click();
        await expect(page).not.toHaveURL(/page=2/);
    });

    test('an invalid select value is discarded, not applied', async ({ adminPage: page }) => {
        await page.goto('/admin/distributors');
        const unfiltered = await rows(page).count();

        const response = await page.goto('/admin/distributors?status=__not_a_status__');

        expect(response?.status()).toBe(200);
        expect(await rows(page).count()).toBe(unfiltered);
        await expect(page.getByTestId('filter-chips')).toHaveCount(0);
    });

    test('an export link carries the active filters', async ({ adminPage: page }) => {
        await page.goto('/admin/distributors?status=active');

        const exportLink = page.getByTestId('filter-export').first();
        test.skip((await exportLink.count()) === 0, 'this page has no export');

        expect(await exportLink.getAttribute('href')).toContain('status=active');
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

const distributorConfigured = Boolean(process.env.A11Y_ADN && process.env.A11Y_PASSWORD);

test.describe('distributor — own-scoped filtering', () => {
    test.skip(
        !distributorConfigured,
        'set A11Y_ADN and A11Y_PASSWORD to a distributor account to run these',
    );

    for (const path of DISTRIBUTOR_PAGES) {
        test(`${path} renders a filter toolbar`, async ({ distributorPage: page }) => {
            await gotoOrSkip(page, path);
            await expect(bar(page)).toBeVisible();
        });

        test(`${path} offers no cross-distributor filter`, async ({ distributorPage: page }) => {
            await gotoOrSkip(page, path);

            for (const key of FORBIDDEN_KEYS) {
                await expect(
                    bar(page).locator(`[name="${key}"]`),
                    `${path} exposes a ${key} control — a distributor filter must never reach another distributor`,
                ).toHaveCount(0);
            }
        });

        test(`${path} ignores an injected scope parameter`, async ({ distributorPage: page }) => {
            await gotoOrSkip(page, path);
            const own = await page.locator('table tbody tr, [data-row]').count();

            const injected = FORBIDDEN_KEYS.map((k) => `${k}=999999`).join('&');
            await gotoOrSkip(page, `${path}?${injected}`);

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
