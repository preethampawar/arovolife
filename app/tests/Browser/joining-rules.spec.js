/**
 * The joining rules, through the real wizard and the real dashboard:
 *
 *  - hard rule 1: joining is free. A completed registration requested no
 *    shop, cart, checkout or payment page and left the new partner's cart
 *    empty and their order history blank;
 *  - hard rule 6: one PAN = one ADN. A PAN the world already holds is refused
 *    at the identity step and the wizard goes no further;
 *  - hard rule 5: the 30-day cooling-off. A partner inside the window sees its
 *    end date (effective date + 30 days), cancels with one confirmation, is
 *    signed out, cannot sign back in, and is sent a written confirmation.
 *    Joining costs nothing, so cancelling the registration refunds nothing
 *    (CancelCoolingOff); a paid ORDER's cooling-off refund runs through the
 *    returns flow, which order-fulfilment.spec.js drives. The D-7 / D-1
 *    reminder mails are a scheduled job covered by Pest, not here.
 *
 * World gsb-basic, frozen at 5 June 2026: root/left/right joined on 1 June.
 */

import { clearThrottles, resetDatabase } from './support/artisan.js';
import { latestMessage } from './support/mailpit.js';
import { completeRegistration, post, pathOf, stepsFor } from './support/registration.js';
import { expect, submitAndConfirm, test } from './fixtures.js';

test.describe.configure({ mode: 'serial', timeout: 3 * 60 * 1000 });
test.afterAll(() => resetDatabase());

/** The last depth-5 reserved company account: no scenario places under it. */
const COMPANY_LEAF = '576780921';

/** The PAN the e2e builder gives the world's first partner (root); checked against the world below. */
const WORLD_ROOT_PAN = 'EZEPE0001K';

test('joining is free: a completed registration buys nothing and charges nothing', async ({ page }) => {
    const result = await completeRegistration(page, { n: 7, placement: COMPANY_LEAF });

    expect(result.stoppedAt, `the wizard rejected ${result.stoppedAt}`).toBeNull();
    expect(result.landed).toBe('/dashboard');

    const commerce = result.requested.filter((url) => /\/(shop|cart|checkout|pay|payments?)(\/|\?|$)/.test(new URL(url).pathname));
    expect(commerce, 'the wizard requested a shop or payment URL').toEqual([]);

    await page.goto('/shop/cart');
    await expect(page.getByText('Your cart is empty.')).toBeVisible();

    await page.goto('/orders');
    expect(pathOf(page.url())).toBe('/orders');
    await expect(page.getByText("You haven't placed any orders yet.")).toBeVisible();
    await expect(page.locator('a[href*="/orders/ORD-"]')).toHaveCount(0);
});

test('one PAN = one ADN: a PAN already registered is refused at the identity step', async ({ page, builtWorld }) => {
    expect(WORLD_ROOT_PAN.endsWith(builtWorld.partners.root.pan_last4), 'the world root holds a different PAN').toBe(true);

    await page.goto(`/register?sponsor=${COMPANY_LEAF}&placement=${COMPANY_LEAF}`);
    expect(pathOf(page.url())).toBe('/register/account');

    for (const [from, to, fields] of stepsFor(8, { pan: WORLD_ROOT_PAN })) {
        if (from === '/register/identity-documents') {
            // Posted as the page's own form would be; the error is flashed
            // once, onto the page the redirect lands on.
            const refused = await page.evaluate(async ({ action, fields }) => {
                const token = document.querySelector('input[name="_token"]')?.value ?? '';
                const resp = await fetch(action, { method: 'POST', body: new URLSearchParams({ ...fields, _token: token }), redirect: 'follow' });

                return { landed: new URL(resp.url).pathname, html: await resp.text() };
            }, { action: from, fields });

            expect(refused.landed, 'the duplicate PAN was accepted').toBe('/register/identity-documents');
            expect(refused.html).toContain('A Direct Seller account already exists for this PAN.');

            return;
        }

        const landed = await post(page, from, fields);

        expect(landed, `posting ${from} was rejected`).toBe(to);
        await page.goto(to);
    }

    throw new Error('the wizard never reached the identity step');
});

test.describe('cooling-off', () => {
    test.use({ partnerKey: 'left' });

    test('shows the end date, cancels in one confirmation, signs out and confirms by email', async ({ distributorPage: page, builtWorld }) => {
        const left = builtWorld.partners.left;

        await page.goto('/dashboard');
        // Joined 1 June 2026 09:10 IST; the window is 30 days.
        await expect(page.getByText('(ends 01 Jul 2026)')).toBeVisible();

        await page.getByRole('link', { name: 'Cancel registration →' }).click();
        await expect(page).toHaveURL(/\/cooling-off$/);
        await expect(page.getByText('01 Jun 2026', { exact: true })).toBeVisible();

        const since = new Date();
        await submitAndConfirm(page, page.getByRole('button', { name: 'Confirm cancellation' }));
        await expect(page).toHaveURL(/\/login/);
        await expect(page.getByText('Your registration has been cancelled.', { exact: false })).toBeVisible();

        const mail = await latestMessage(left.email, { since });
        expect(mail.Subject.toLowerCase()).toContain('cancel');

        clearThrottles();
        await page.goto('/login');
        await page.locator('input[name="login"]').fill(left.adn);
        await page.locator('input[name="password"]').fill(builtWorld.password);
        await page.locator('form').filter({ has: page.locator('input[name="password"]') }).evaluate((f) => f.submit());
        await page.waitForLoadState('networkidle');
        expect(pathOf(page.url()), 'a cancelled partner signed back in').toBe('/login');
        await expect(page.getByText('This account has been closed.', { exact: false })).toBeVisible();
    });
});
