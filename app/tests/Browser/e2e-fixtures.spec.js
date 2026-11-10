// @ts-check
import { test, expect } from './fixtures.js';
import { latestMessage } from './support/mailpit.js';

/** The S2 building blocks: saved admin login, world-based partner login, engine figures on screen, Mailpit OTP. */
test.describe('e2e fixtures', () => {
    test('adminPage carries the saved admin login', async ({ adminPage }) => {
        await adminPage.goto('/admin');
        await expect(adminPage).toHaveURL(/\/admin/);
    });

    test('distributorPage logs in as the world partner', async ({ distributorPage, builtWorld }) => {
        expect(builtWorld.partners.root.adn).toMatch(/^\d{9}$/);
        await expect(distributorPage).toHaveURL(/\/dashboard/);
    });

    test('a partner of another side can be chosen', async ({ page, builtWorld }) => {
        const { loginAsDistributor } = await import('./fixtures.js');
        await loginAsDistributor(page, builtWorld.partners.left.adn, builtWorld.password);
        await expect(page).toHaveURL(/\/dashboard/);
    });

    test('mail the app sends can be read from Mailpit', async ({ page, builtWorld }) => {
        const email = builtWorld.partners.right.email;
        const since = new Date();
        await page.goto('/forgot-password');
        await page.locator('input[name="email"]').fill(email);
        await page.locator('input[name="email"]').evaluate((input) => input.form?.submit());
        const message = await latestMessage(email, { since });
        expect(message.Text).toContain('/reset-password/');
    });
});
