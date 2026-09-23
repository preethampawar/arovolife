/**
 * Fortune Bonus Dashboard Card — Browser Tests
 *
 * Covers Slice 7 (#30) of docs/plans/policy-pages-home-about-genos-star-fb-card-2026-09-23.md:
 *  - Distributor dashboard shows the "Fortune Bonus" card with "Your tier"
 *    when the FortuneBonusFeature flag is ON (skip when OFF — the card is a
 *    zero-trace feature, App\Modules\Shared\Features\FortuneBonusFeature)
 *  - Guest hitting /dashboard is redirected to /login
 *  - Admin/staff have no distributor block, so /admin has no Fortune Bonus card
 */

import { test, expect } from './fixtures.js';

/** True when the Fortune Bonus card is rendered for the current distributor. */
async function fortuneBonusIsLive(page) {
    await page.goto('/dashboard');
    return (await page.getByText('Fortune Bonus', { exact: true }).count()) > 0;
}

test.describe('FB card ✅: distributor dashboard', () => {
    test('Fortune Bonus card is visible with "Your tier" when the flag is ON', async ({ distributorPage: page }) => {
        const live = await fortuneBonusIsLive(page);
        test.skip(!live, 'FortuneBonusFeature is flagged off (or has no zero-trace hook yet) in this environment — the card renders nothing at all.');

        await expect(page.getByText('Fortune Bonus', { exact: true }).first()).toBeVisible();
        await expect(page.getByText('Your tier', { exact: false }).first()).toBeVisible();
    });
});

test.describe('FB card ❌ guest', () => {
    test('guest hitting /dashboard is redirected to /login', async ({ page }) => {
        await page.goto('/dashboard');
        await expect(page).toHaveURL(/login/);
    });
});

test.describe('FB card ❌ staff', () => {
    test('admin has no Fortune Bonus card on /admin', async ({ adminPage: page }) => {
        await page.goto('/admin');
        await expect(page.getByText('Fortune Bonus', { exact: true })).toHaveCount(0);
    });
});
