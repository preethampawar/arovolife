import { clearThrottles, ensureWorld } from './support/artisan.js';
import { ADMIN_STATE, seal, test as base } from './support/e2e-test.js';

/**
 * Pre-authenticated pages on the e2e stack (http://localhost:8085).
 *
 * - adminPage: a fresh sealed context carrying the admin login globalSetup
 *   saved (admin@arovolife.test / admin12345), so no spec logs in as admin.
 * - distributorPage: logs in as one partner of the current WORLD — a named
 *   scenario built on a fresh reset and replayed through the engines (see
 *   ensureWorld in support/artisan.js). Override per file:
 *     test.use({ world: { name: 'gsb-basic', asOf: '2026-06-05 06:00:00' }, partnerKey: 'left' });
 *   The world object (partners, ADNs, orders) is the `world` fixture.
 */

async function getCsrfToken(page) {
    return page.evaluate(() =>
        document.querySelector('meta[name="csrf-token"]')?.content ??
        document.querySelector('input[name="_token"]')?.value ?? ''
    );
}

/** Log in by ADN (or e-mail) through the real form. Clears the login throttle first. */
export async function loginAsDistributor(page, login, password) {
    clearThrottles();
    await page.goto('/login');
    await page.locator('input[name="login"]').fill(login);
    await page.locator('input[name="password"]').fill(password);
    await page.locator('form').filter({ has: page.locator('input[name="password"]') }).evaluate((f) => f.submit());
    await page.waitForURL('**/dashboard**');
}

/** Admin login through the form, for a page that is not `adminPage`. Prefer the `adminPage` fixture. */
export async function loginAsAdmin(page) {
    clearThrottles();
    await page.goto('/login');
    await page.locator('input[name="login"]').fill('admin@arovolife.test');
    await page.locator('input[name="password"]').fill('admin12345');
    await page.locator('form').filter({ has: page.locator('input[name="password"]') }).evaluate((f) => f.submit());
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

/** Back-office login through the form as a scenario staff key (finance, operations, compliance, developer). */
export async function loginAsStaff(page, builtWorld, staffKey) {
    const staff = builtWorld.staff?.[staffKey];
    if (!staff) {
        throw new Error(`World ${builtWorld.scenario} has no staff '${staffKey}' (has: ${Object.keys(builtWorld.staff ?? {}).join(', ')})`);
    }
    clearThrottles();
    await page.goto('/login');
    await page.locator('input[name="login"]').fill(staff.email);
    await page.locator('input[name="password"]').fill(builtWorld.password);
    await page.locator('form').filter({ has: page.locator('input[name="password"]') }).evaluate((f) => f.submit());
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

export const test = base.extend({
    world: [{ name: 'gsb-basic', asOf: '2026-06-05 06:00:00' }, { option: true }],
    partnerKey: ['root', { option: true }],
    staffKey: ['finance', { option: true }],

    builtWorld: async ({ world }, use) => {
        const { name, ...options } = world;
        await use(ensureWorld(name, options));
    },

    // Auto: every test runs on its declared world, even one that only uses
    // `page` or `adminPage` (each `playwright test` process starts from the
    // base snapshot, so without this an admin-only spec would see no data).
    worldReady: [async ({ builtWorld }, use) => { await use(builtWorld); }, { auto: true }],

    adminPage: async ({ browser, baseURL }, use) => {
        const context = await browser.newContext({ baseURL, storageState: ADMIN_STATE, serviceWorkers: 'block' });
        await seal(context);
        const page = await context.newPage();
        await use(page);
        await context.close();
    },

    distributorPage: async ({ page, builtWorld, partnerKey }, use) => {
        const partner = builtWorld.partners[partnerKey];
        if (!partner) {
            throw new Error(`World ${builtWorld.scenario} has no partner '${partnerKey}' (has: ${Object.keys(builtWorld.partners).join(', ')})`);
        }
        await loginAsDistributor(page, partner.adn, builtWorld.password);
        await use(page);
    },

    // The `page` logged in as one back-office role: test.use({ staffKey: 'operations' }).
    staffPage: async ({ page, builtWorld, staffKey }, use) => {
        await loginAsStaff(page, builtWorld, staffKey);
        await use(page);
    },
});

export { getCsrfToken };

/**
 * Confirmation-dialog helper.
 *
 * The platform-wide confirm modal (resources/views/components/confirm-modal.blade.php)
 * only intercepts a submit when the form carries `data-confirm` — a form
 * marked with only `data-confirm-impact` (most of the inventory module's
 * archive/send/dispatch/receive actions) submits natively with no dialog at
 * all. Use this helper for any submit button so both cases work: it clicks
 * the button, and if the modal appears, clicks its Confirm button too.
 */
export async function submitAndConfirm(page, submitLocator) {
    await submitLocator.click();
    const confirmButton = page.locator('#confirm-modal-ok');
    if (await confirmButton.isVisible().catch(() => false)) {
        await confirmButton.click();
    }
}

/**
 * Reads the "On hand" quantity for a given SKU + warehouse code from the
 * admin Stock screen (/admin/inventory/stock). Returns null if no matching
 * row is found (e.g. the variant has never had stock at that warehouse).
 */
export async function readStockOnHand(page, sku, warehouseCode) {
    await page.goto(`/admin/inventory/stock?q=${encodeURIComponent(sku)}&warehouse_code=${encodeURIComponent(warehouseCode)}`);
    const row = page.locator('tbody tr.hover\\:bg-gray-50').filter({ hasText: sku }).filter({ hasText: warehouseCode }).first();
    if ((await row.count()) === 0) {
        return null;
    }
    const onHandText = (await row.locator('td').nth(2).textContent())?.trim() ?? '';
    const value = parseInt(onHandText.replace(/[^\d-]/g, ''), 10);
    return Number.isFinite(value) ? value : null;
}

export { expect } from './support/e2e-test.js';
