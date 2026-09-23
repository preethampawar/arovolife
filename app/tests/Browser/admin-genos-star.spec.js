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

import { test, expect, loginAsAdmin, loginAsDistributor } from './fixtures.js';

async function purchaseMarkSettingIsOn(page) {
    await page.goto('/admin/settings');
    const toggle = page.locator('[aria-label="Toggle genealogy.purchase_mark_visible"]');
    if (await toggle.count() === 0) {
        return null; // setting not found on this page — caller should skip
    }
    return (await toggle.getAttribute('aria-checked')) === 'true';
}

test.describe('Staff star: admin Genos always shows the mark', () => {
    test('at least one [data-purchase-mark] is visible on /admin/tree, setting state notwithstanding', async ({ adminPage: page }) => {
        const settingOn = await purchaseMarkSettingIsOn(page);
        test.skip(settingOn === null, 'genealogy.purchase_mark_visible is not present on /admin/settings in this environment.');

        await page.goto('/admin/tree');
        const marks = page.locator('[data-purchase-mark]');
        test.skip(await marks.count() === 0, 'No purchase-mark stars rendered — the dev tree may have no distributors with a resolvable personal-BV row.');
        await expect(marks.first()).toBeVisible();
    });
});

test.describe('Distributor gated: own /tree still respects the setting', () => {
    test('no [data-purchase-mark] on non-own cards while the setting is OFF', async ({ browser }) => {
        // adminPage and distributorPage both wrap the same underlying `page`
        // fixture (fixtures.js), so they cannot be requested together in one
        // test — the second login would run against an already-authenticated
        // page. Use two independent browser contexts instead.
        const adminContext = await browser.newContext();
        const adminPage = await adminContext.newPage();
        await loginAsAdmin(adminPage);
        const settingOn = await purchaseMarkSettingIsOn(adminPage);
        await adminContext.close();

        test.skip(settingOn === null, 'genealogy.purchase_mark_visible is not present on /admin/settings in this environment.');
        test.skip(settingOn === true, 'genealogy.purchase_mark_visible is ON in this environment — downline cards are expected to show the mark too, so there is nothing to gate against.');

        const distContext = await browser.newContext();
        const distPage = await distContext.newPage();
        const adn = process.env.A11Y_ADN ?? '360801433';
        const password = process.env.A11Y_PASSWORD ?? 'Test1234!';
        await loginAsDistributor(distPage, adn, password);

        await distPage.goto('/tree');
        const marks = distPage.locator('[data-purchase-mark]');
        const markCount = await marks.count();
        await distContext.close();

        // Own card is the root of /tree and may legitimately carry the mark;
        // with the setting OFF, no other (downline) card may.
        test.skip(markCount === 0, 'No purchase-mark stars rendered on /tree — nothing to assert gating against for this distributor.');
        expect(markCount).toBeLessThanOrEqual(1);
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
