import { test as base } from '@playwright/test';

/**
 * Login helpers. Both functions POST to /login via form submission
 * (not fetch) so session cookies are set correctly.
 *
 * Admin: admin@arovolife.test / admin12345
 * Distributor: identified by ADN — password reset in dev to Test1234!
 */

async function getCsrfToken(page) {
    const token = await page.evaluate(() =>
        document.querySelector('meta[name="csrf-token"]')?.content ??
        document.querySelector('input[name="_token"]')?.value ?? ''
    );
    return token;
}

export async function loginAsAdmin(page) {
    await page.goto('/login');
    const token = await getCsrfToken(page);
    await page.evaluate(async ({ token }) => {
        const resp = await fetch('/login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ login: 'admin@arovolife.test', password: 'admin12345', _token: token }),
        });
        return resp.url;
    }, { token });
    await page.goto('/admin');
    await page.waitForURL('**/admin**');
}

export async function loginAsDistributor(page, adn, password = 'Test1234!') {
    await page.goto('/login');
    await page.fill('input[placeholder="9-digit ADN"]', adn);
    await page.fill('input[type="password"]', password);
    await page.locator('form').evaluate(f => f.submit());
    await page.waitForURL('**/dashboard**');
}

/** Extended test fixture that provides pre-authenticated pages. */
export const test = base.extend({
    adminPage: async ({ page }, use) => {
        await loginAsAdmin(page);
        await use(page);
    },
    distributorPage: async ({ page }, use) => {
        // The dev database is reseeded from time to time, so the ADN is an env
        // override rather than a constant: a hard-coded one that no longer
        // exists fails as a login timeout, which reads like a broken page
        // instead of a missing fixture. Set A11Y_ADN / A11Y_PASSWORD to point
        // at whichever distributor your dev database actually has.
        const adn = process.env.A11Y_ADN ?? '394325128';
        const password = process.env.A11Y_PASSWORD ?? 'Test1234!';
        await loginAsDistributor(page, adn, password);
        await use(page);
    },
});

export { expect } from '@playwright/test';
