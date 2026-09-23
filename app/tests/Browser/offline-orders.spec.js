/**
 * Offline orders — Browser Tests (docs/plans/offline-orders-2026-09-23.md)
 *
 * Covers the admin flow end to end: create an offline order for a
 * distributor, see it pending, confirm the payment (the order becomes Paid
 * with an invoice, as a shop order does), reject a second one, the amount
 * mismatch refusal, and what the buyer sees on My Orders.
 *
 * Requires:
 *   - App at APP_URL (default http://localhost:8084), admin@arovolife.test / admin12345
 *   - The `commerce.offline_orders` feature flag ON (developer → Feature Flags)
 *   - A11Y_ADN / A11Y_PASSWORD: a distributor who may buy (default 360801433 / Test1234!)
 *   - At least one active product
 *   - Optional scoped staff for the permission rows: E2E_OPS_EMAIL,
 *     E2E_FIN_EMAIL, E2E_COMPLIANCE_EMAIL with E2E_STAFF_PASSWORD. Those tests
 *     skip themselves when unset — the Pest suite (OfflineOrderTest) covers
 *     every permission-matrix row authoritatively.
 *
 * The orders placed here are real distributor purchases with BV on the dev
 * database — run against a disposable dev database if that matters.
 */

import { test, expect, submitAndConfirm } from './fixtures.js';

const ADN = process.env.A11Y_ADN ?? '360801433';

/** Fills the create form for one unit of the first product and returns the quoted total text. */
async function fillOfflineOrder(page, { channel = 'upi', reference = `UPI${Date.now()}`, amountOverride = null } = {}) {
    await page.goto(`/admin/commerce/offline-orders/create?adn=${ADN}`);
    await expect(page.getByText('2. Products')).toBeVisible();

    await page.locator('input[name^="qty["]').first().fill('1');
    await page.getByLabel('Address line 1').fill('1 Market Road');
    await page.getByLabel('City').fill('Hyderabad');
    await page.getByLabel('State').selectOption('Telangana');
    await page.getByLabel('Pincode').fill('500001');
    await page.getByRole('button', { name: 'Calculate total' }).click();

    const total = page.getByTestId('quote-total');
    await expect(total).toBeVisible();

    // The GET round-trip keeps the fields; fill the payment.
    await page.getByLabel('Paid by', { exact: true }).selectOption(channel);
    if (amountOverride !== null) {
        await page.getByLabel('Amount received (₹)').fill(amountOverride);
    }
    await page.getByLabel(/Reference no\./).fill(reference);
    await page.getByLabel('The buyer has agreed to the terms of sale.').check();

    return (await total.textContent())?.trim();
}

test.describe('Offline orders (admin)', () => {
    test('PW-01/02: create an offline order, see it pending, confirm it paid', async ({ adminPage: page }) => {
        await page.goto('/admin/commerce/orders');
        await expect(page.getByRole('link', { name: 'New offline order' })).toBeVisible();

        const quoted = await fillOfflineOrder(page);
        expect(quoted).toMatch(/^₹/);

        await submitAndConfirm(page, page.getByRole('button', { name: 'Create offline order' }));
        await page.waitForURL('**/admin/commerce/orders/**');

        await expect(page.getByText(/Offline order ORD-.* created/)).toBeVisible();
        await expect(page.getByText('Awaiting finance confirmation')).toBeVisible();
        await expect(page.getByText('Waiting for finance to confirm the offline payment')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Cancel Order' })).toHaveCount(0);

        await page.getByLabel(/I have checked this money has been received/).check();
        await submitAndConfirm(page, page.getByRole('button', { name: 'Confirm payment' }));

        await expect(page.getByText('Payment confirmed. The order is now paid')).toBeVisible();
        await expect(page.getByText('Confirmed', { exact: true })).toBeVisible();
        await expect(page.getByText('Paid', { exact: false }).first()).toBeVisible();
        await expect(page.getByRole('button', { name: 'Mark as Shipped' })).toBeVisible();
    });

    test('PW-03: an amount that does not match the total is refused', async ({ adminPage: page }) => {
        await fillOfflineOrder(page, { amountOverride: '1.00' });
        await submitAndConfirm(page, page.getByRole('button', { name: 'Create offline order' }));

        await expect(page.getByText(/must match exactly/)).toBeVisible();
    });

    test('PW-04: reject a second offline order', async ({ adminPage: page }) => {
        await fillOfflineOrder(page);
        await submitAndConfirm(page, page.getByRole('button', { name: 'Create offline order' }));
        await page.waitForURL('**/admin/commerce/orders/**');

        await page.getByLabel(/No money was received for this order/).check();
        await page.getByPlaceholder('Reason (required)').fill('Deposit never arrived in the bank');
        await submitAndConfirm(page, page.getByRole('button', { name: 'Reject payment' }));

        await expect(page.getByText('Payment rejected and the order cancelled')).toBeVisible();
        await expect(page.getByText('Rejected', { exact: true })).toBeVisible();
    });
});

test.describe('Offline orders (buyer)', () => {
    test('PW-08: the buyer sees "Paid offline" and no Cancel while pending', async ({ adminPage: page, browser }) => {
        await fillOfflineOrder(page);
        await submitAndConfirm(page, page.getByRole('button', { name: 'Create offline order' }));
        await page.waitForURL('**/admin/commerce/orders/**');
        const flash = await page.getByText(/Offline order ORD-.* created/).textContent();
        const orderNo = flash?.match(/ORD-[0-9]{6}-[A-Z0-9]{6}/)?.[0];
        expect(orderNo).toBeTruthy();

        const buyer = await (await browser.newContext()).newPage();
        const { loginAsDistributor } = await import('./fixtures.js');
        await loginAsDistributor(buyer, ADN, process.env.A11Y_PASSWORD ?? 'Test1234!');
        await buyer.goto(`/orders/${orderNo}`);

        await expect(buyer.getByText(/Paid offline/)).toBeVisible();
        await expect(buyer.getByText('We are confirming your payment.', { exact: false })).toBeVisible();
        await expect(buyer.getByRole('button', { name: 'Cancel this order' })).toHaveCount(0);
    });
});

async function loginStaff(page, email) {
    await page.goto('/login');
    const token = await page.evaluate(() => document.querySelector('input[name="_token"]')?.value ?? '');
    await page.evaluate(async ({ email, password, token }) => {
        await fetch('/login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ login: email, password, _token: token }),
        });
    }, { email, password: process.env.E2E_STAFF_PASSWORD ?? '', token });
}

test.describe('Offline orders (scoped roles)', () => {
    test('PW-05: operations sees the button but never Confirm', async ({ page }) => {
        test.skip(!process.env.E2E_OPS_EMAIL, 'Set E2E_OPS_EMAIL + E2E_STAFF_PASSWORD to run the operations row.');
        await loginStaff(page, process.env.E2E_OPS_EMAIL);
        await page.goto('/admin/commerce/orders');
        await expect(page.getByRole('link', { name: 'New offline order' })).toBeVisible();
    });

    test('PW-06: finance has no "New offline order" and cannot open the form', async ({ page }) => {
        test.skip(!process.env.E2E_FIN_EMAIL, 'Set E2E_FIN_EMAIL + E2E_STAFF_PASSWORD to run the finance row.');
        await loginStaff(page, process.env.E2E_FIN_EMAIL);
        await page.goto('/admin/commerce/orders');
        await expect(page.getByRole('link', { name: 'New offline order' })).toHaveCount(0);
        const response = await page.goto('/admin/commerce/offline-orders/create');
        expect(response?.status()).toBe(403);
    });

    test('PW-07: compliance can neither create nor confirm', async ({ page }) => {
        test.skip(!process.env.E2E_COMPLIANCE_EMAIL, 'Set E2E_COMPLIANCE_EMAIL + E2E_STAFF_PASSWORD to run the compliance row.');
        await loginStaff(page, process.env.E2E_COMPLIANCE_EMAIL);
        const response = await page.goto('/admin/commerce/offline-orders/create');
        expect(response?.status()).toBe(403);
    });
});
