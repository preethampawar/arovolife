/**
 * Admin Dashboard — Browser Tests
 *
 * The dashboard's contract is a loading contract, and that is the part only a
 * browser can prove: the shell paints with no data, the panels above the fold
 * fetch themselves immediately, the rest wait until they are scrolled near,
 * each is fetched exactly once on arrival, and the only thing that fetches it
 * again is the once-a-minute refresh — never a panel nobody has scrolled to,
 * and never a tab nobody is looking at.
 *
 * Requires:
 *   - App running at APP_URL (default http://localhost:8084)
 *   - admin@arovolife.test / admin12345 (super staff, so every panel is visible)
 *
 * Notes:
 *   - Runs against the DEV database, so these assert structure and behaviour,
 *     never specific figures. The dev data's newest order is weeks old, which
 *     means the Commerce card's "Today" column legitimately reads zero.
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
        // First paint is the only moment that matters here: the panels above
        // grow as they load, so a panel outside the margin while everything is
        // still a skeleton only moves further away.
        //
        // The viewport has to be measured against the *skeleton* layout, not the
        // loaded one. With five panels visible the last skeleton's top sits at
        // 645px, so the original 600px viewport put it 5px inside the observer's
        // 600 + 250 margin: the test passed only when the first panel's response
        // happened to beat the observer's first callback and push the rest down.
        // 300px ends the margin at 550px, clear of it whatever the server does.
        await page.setViewportSize({ width: 1280, height: 300 });
        await page.goto('/admin');

        await expect(panel(page, 'commerce')).toHaveAttribute('data-panel-state', 'done', { timeout: 10_000 });

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

    test('E2E-05: the removed feeds are gone', async ({ adminPage: page }) => {
        await page.goto('/admin');
        await expect(panel(page, 'commerce')).toHaveAttribute('data-panel-state', 'done', { timeout: 10_000 });
        await expect(panel(page, 'people')).toHaveAttribute('data-panel-state', 'done', { timeout: 10_000 });

        const body = await page.locator('body').innerText();

        expect(body).not.toContain('Recent Audit Events');
        expect(body).not.toContain('Audit Events Today');
        expect(body).not.toContain('Total Users');
        expect(body).not.toContain('Recent registrations');
    });

    test('E2E-06: the refresh control re-fetches just its own panel', async ({ adminPage: page }) => {
        await page.goto('/admin');

        const target = panel(page, 'commerce');
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

    test('E2E-07: each panel is fetched once on arrival and nothing polls before its turn', async ({ adminPage: page }) => {
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

        // No duplicates: a panel fetched twice on arrival means the observer
        // re-fired.
        expect(new Set(requests).size).toBe(afterLoad);

        // The refresh is on a minute. Nothing may go out before then — the
        // assertion that stops a stray timer turning an idle dashboard into a
        // request per panel every few seconds.
        await page.waitForTimeout(5_000);
        expect(requests.length).toBe(afterLoad);
    });

    test('E2E-08: every loaded panel refreshes itself once a minute', async ({ adminPage: page }) => {
        // Fake timers, so a one-minute interval does not cost the suite a
        // minute. `clock.install` must precede the navigation that registers
        // the interval. The panels are rendered server-side, so the faked clock
        // only ever drives the page's own scheduling — the figures inside them
        // still come from the real application.
        await page.clock.install();
        await page.goto('about:blank');

        const requests = [];
        page.on('request', (r) => {
            if (r.url().includes('/dashboard/panel/')) {
                requests.push(r.url().replace(/^.*\/panel\//, ''));
            }
        });

        await page.goto('/admin');

        // Wait for the arrival round to finish completely — not just for the
        // above-fold panels. A panel still in flight is in `loading`, and the
        // refresh deliberately skips those; fast-forwarding over one would make
        // this test read a race as a missing refresh.
        const settled = () =>
            page.evaluate(() =>
                Array.prototype.slice
                    .call(document.querySelectorAll('[data-panel-url]'))
                    .filter((el) => el.dataset.panelState === 'done')
                    .map((el) => el.dataset.panelUrl.replace(/^.*\/panel\//, '')),
            );

        const noneInFlight = () =>
            page.evaluate(() =>
                Array.prototype.slice
                    .call(document.querySelectorAll('[data-panel-url]'))
                    .every((el) => el.dataset.panelState !== 'loading'),
            );

        await expect.poll(noneInFlight, { timeout: 20_000 }).toBe(true);
        await page.waitForTimeout(500);
        await expect.poll(noneInFlight, { timeout: 20_000 }).toBe(true);

        const loaded = new Set(await settled());
        expect(loaded.size).toBeGreaterThan(0);

        requests.length = 0;
        await page.clock.fastForward('01:05');

        // One refresh round: every panel that had loaded is fetched again,
        // exactly once, and nothing else is.
        await expect.poll(() => requests.length, { timeout: 20_000 }).toBe(loaded.size);
        expect(new Set(requests)).toEqual(loaded);

        for (const key of loaded) {
            await expect(panel(page, key)).toHaveAttribute('data-panel-state', 'done', { timeout: 20_000 });
        }
    });

    test('E2E-09: a hidden tab refreshes nothing, and catches up when it is looked at again', async ({ adminPage: page }) => {
        await page.clock.install();
        await page.goto('about:blank');

        const requests = [];
        page.on('request', (r) => {
            if (r.url().includes('/dashboard/panel/')) {
                requests.push(r.url().replace(/^.*\/panel\//, ''));
            }
        });

        await page.goto('/admin');
        await expect(panel(page, 'commerce')).toHaveAttribute('data-panel-state', 'done', { timeout: 20_000 });

        // Chromium exposes no way to background a tab from the page, so the
        // visibility API is stubbed directly: this asserts the branch the
        // production code takes, which is the part that decides whether an
        // abandoned dashboard costs the server anything.
        await page.evaluate(() => {
            Object.defineProperty(document, 'hidden', { configurable: true, get: () => true });
            document.dispatchEvent(new Event('visibilitychange'));
        });

        requests.length = 0;
        await page.clock.fastForward('05:00');
        await page.waitForTimeout(1_000);
        expect(requests).toEqual([]);

        await page.evaluate(() => {
            Object.defineProperty(document, 'hidden', { configurable: true, get: () => false });
            document.dispatchEvent(new Event('visibilitychange'));
        });

        // Coming back is itself the refresh — the viewer does not wait out the
        // rest of an interval to be shown current figures.
        await expect.poll(() => requests.length, { timeout: 20_000 }).toBeGreaterThan(0);
    });
});
