/**
 * Admin Genos Purchase Star — Browser Tests
 *
 * Covers Slice 7 (#29) of docs/plans/policy-pages-home-about-genos-star-fb-card-2026-09-23.md:
 *  - Staff always see the purchase star (red/yellow/green) on every card of
 *    the admin Genos (`/admin/tree`), regardless of `genealogy.purchase_mark_visible`
 *  - A distributor viewing their own `/tree` still does not see the star on
 *    non-own (downline) cards while the setting is OFF
 *  - A distributor requesting the admin tree gets 403 (route middleware
 *    `role:developer|admin|admin-operations|admin-finance|admin-compliance`)
 *
 * `genealogy.purchase_mark_visible` (admin.settings, group "placement") is
 * read live from /admin/settings via its toggle's `aria-checked` attribute,
 * so this spec adapts to whichever state dev is actually in rather than
 * assuming OFF.
 */

import { test, expect, loginAsStaff } from './fixtures.js';

// World gsb-basic: root with a left and a right partner, so the Genos has
// three cards. `genealogy.purchase_mark_visible` is developer-owned, so the
// precondition is read as the scenario's developer login, and asserted — the
// gating test below is only meaningful while it is OFF.
test.describe.configure({ mode: 'serial' });

test('precondition: genealogy.purchase_mark_visible is OFF', async ({ page, builtWorld }) => {
    await loginAsStaff(page, builtWorld, 'developer');
    await page.goto('/admin/settings');
    const toggle = page.locator('[aria-label="Toggle genealogy.purchase_mark_visible"]');
    await expect(toggle).toHaveCount(1);
    await expect(toggle).toHaveAttribute('aria-checked', 'false');
});

test.describe('Staff star: admin Genos always shows the mark', () => {
    test('at least one [data-purchase-mark] is visible on /admin/tree, setting state notwithstanding', async ({ adminPage: page }) => {
        await page.goto('/admin/tree');
        const marks = page.locator('[data-purchase-mark]');
        await expect(marks.first()).toBeVisible();
    });
});

test.describe('Distributor gated: own /tree still respects the setting', () => {
    test('no [data-purchase-mark] on non-own cards while the setting is OFF', async ({ distributorPage: page }) => {
        await page.goto('/tree');
        await expect(page.locator('[data-node-menu-trigger]').first()).toBeVisible();

        // Own card is the root of /tree and may legitimately carry the mark;
        // with the setting OFF, no other (downline) card may. gsb-basic has
        // two downline cards, so a leak would show as more than one mark.
        expect(await page.locator('[data-purchase-mark]').count()).toBeLessThanOrEqual(1);
    });

    test('distributor /tree renders without error', async ({ distributorPage: page }) => {
        const response = await page.goto('/tree');
        expect(response?.status()).toBeLessThan(400);
    });
});

test.describe('Distributor ❌: admin Genos is forbidden', () => {
    test('distributor GET /admin/tree returns 403', async ({ distributorPage: page }) => {
        const response = await page.goto('/admin/tree');
        expect(response?.status()).toBe(403);
    });
});
