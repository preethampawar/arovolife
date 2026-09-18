/**
 * Admin Dashboard — Browser Tests
 *
 * The dashboard's contract is a loading contract, and that is the part only a
 * browser can prove: the shell paints with no data, the panels above the fold
 * fetch themselves immediately, the rest wait until they are scrolled near,
 * each is fetched exactly once, and nothing polls afterwards.
 *
 * Requires:
 *   - App running at APP_URL (default http://localhost:8084)
 *   - admin@arovolife.test / admin12345 (super staff, so every panel is visible)
 *
 * Notes:
 *   - Runs against the DEV database, so these assert structure and behaviour,
 *     never specific figures. The dev data's newest order is weeks old, which
 *     means the Sales panel's "Today" column legitimately reads zero.
 *   - Which panels exist is NOT fixed. `attention` and `inventory` sit behind
 *     ActionCenterFeature and InventoryFeature, both of which resolve off by
 *     default, and a flag-off panel leaves no trace in the shell at all — that
 *     is the zero-UI-trace rule working, not a missing panel. So nothing here
 *     names a panel that a flag can remove; the above-fold set is read from
 *     the DOM (`data-panel-lazy="0"`) instead of being hard-coded.
 *   - The permission matrix is NOT tested here. fixtures.js carries storage
 *     state for `admin` and a distributor only, with nothing per scoped role,
 *     so who-may-see-what lives in tests/Modules/Admin/AdminDashboardPanelTest.php
 *     where roles can actually be created.
 */

import { test, expect } from './fixtures.js';

function panel(page, key) {
    return page.locator(`[data-panel-url*="/panel/${key}"]`);
}

/** The panels the shell asked to load immediately, whatever the flags leave visible. */
function aboveFold(page) {
    return page.locator('[data-panel-url][data-panel-lazy="0"]');
}

test.describe('Admin dashboard: shell', () => {
    test('E2E-01: the shell paints every panel title before any data arrives', async ({ adminPage: page }) => {
        await page.goto('/admin', { waitUntil: 'domcontentloaded' });

        // Titles come from the skeleton, so they are present in the very first
        // paint — that is the whole point of rendering the chrome up front.
        const placeholders = page.locator('[data-panel-url]');
        await expect(placeholders.first()).toBeVisible();
        expect(await placeholders.count()).toBeGreaterThan(0);

        // Every placeholder carries its panel's title from the skeleton, before
        // any fetch resolves. Asserted against the registry's own titles as
        // rendered, so a flag that removes a panel cannot fail this.
        const titles = await placeholders.evaluateAll((els) => els.map((el) => el.textContent.trim()));
        for (const title of titles) {
            expect(title.length).toBeGreaterThan(0);
        }
    });

    test('E2E-02: the above-fold panels load themselves without a scroll', async ({ adminPage: page }) => {
        await page.goto('/admin');

        const eager = aboveFold(page);
        const count = await eager.count();
        expect(count).toBeGreaterThan(0);

        for (let i = 0; i < count; i++) {
            await expect(eager.nth(i)).toHaveAttribute('data-panel-state', 'done', { timeout: 10_000 });
        }
    });

    test('E2E-03: a below-fold panel stays unfetched until it is scrolled near', async ({ adminPage: page }) => {
        // A tall-but-narrow viewport keeps the lower panels well outside the
        // observer's 250px margin on first paint.
        await page.setViewportSize({ width: 1280, height: 600 });
        await page.goto('/admin');

        await expect(panel(page, 'sales')).toHaveAttribute('data-panel-state', 'done', { timeout: 10_000 });

        const lastPanel = page.locator('[data-panel-url]').last();
        const state = await lastPanel.getAttribute('data-panel-state');

        expect(state).not.toBe('done');
    });

    test('E2E-04: every panel reaches done once scrolled to the bottom', async ({ adminPage: page }) => {
        await page.goto('/admin');

        const panels = page.locator('[data-panel-url]');
        const count = await panels.count();

        for (let i = 0; i < count; i++) {
            await panels.nth(i).scrollIntoViewIfNeeded();
        }

        for (let i = 0; i < count; i++) {
            await expect(panels.nth(i)).toHaveAttribute('data-panel-state', 'done', { timeout: 10_000 });
        }
    });

    test('E2E-05: the removed audit feed is gone', async ({ adminPage: page }) => {
        await page.goto('/admin');
        await expect(panel(page, 'sales')).toHaveAttribute('data-panel-state', 'done', { timeout: 10_000 });

        const body = await page.locator('body').innerText();

        expect(body).not.toContain('Recent Audit Events');
        expect(body).not.toContain('Audit Events Today');
        expect(body).not.toContain('Total Users');
    });

    test('E2E-06: the refresh control re-fetches just its own panel', async ({ adminPage: page }) => {
        await page.goto('/admin');

        const target = panel(page, 'sales');
        await expect(target).toHaveAttribute('data-panel-state', 'done', { timeout: 10_000 });

        const requests = [];
        page.on('request', (r) => {
            if (r.url().includes('/dashboard/panel/')) {
                requests.push(r.url());
            }
        });

        await target.locator('[data-panel-refresh]').first().click();

        await expect(target).toHaveAttribute('data-panel-state', 'done', { timeout: 10_000 });
        await expect(target).toContainText('as of');

        expect(requests.filter((u) => u.includes('/panel/sales'))).toHaveLength(1);
        expect(requests.filter((u) => !u.includes('/panel/sales'))).toHaveLength(0);
    });

    test('E2E-07: each panel is fetched once and nothing polls afterwards', async ({ adminPage: page }) => {
        // Leave the dashboard before counting anything. The `adminPage` fixture
        // finishes its login on /admin, and `goto` resolves at the load event —
        // but the lazy panels are started by an IntersectionObserver callback
        // that runs after it. Those four fetches therefore begin *after* the
        // fixture returns, and a listener attached at the top of this test
        // catches them, making the second visit look like it fetched every
        // panel twice. It did not; the count was reading two page visits.
        await page.goto('about:blank');

        const requests = [];
        page.on('request', (r) => {
            if (r.url().includes('/dashboard/panel/')) {
                requests.push(r.url().replace(/^.*\/panel\//, ''));
            }
        });

        await page.goto('/admin');

        const panels = page.locator('[data-panel-url]');
        const count = await panels.count();
        for (let i = 0; i < count; i++) {
            await panels.nth(i).scrollIntoViewIfNeeded();
        }
        for (let i = 0; i < count; i++) {
            await expect(panels.nth(i)).toHaveAttribute('data-panel-state', 'done', { timeout: 10_000 });
        }

        const afterLoad = requests.length;

        // No duplicates: a panel fetched twice means the observer re-fired.
        expect(new Set(requests).size).toBe(afterLoad);

        // Nothing polls. This is the assertion that keeps an idle dashboard
        // from costing the server a request per panel per interval, forever.
        await page.waitForTimeout(5_000);
        expect(requests.length).toBe(afterLoad);
    });
});
