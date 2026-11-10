// @ts-check
import { test, expect } from './support/e2e-test.js';
import { artisan, setClock } from './support/artisan.js';

test.describe('e2e stack smoke', () => {
    test('runs on the e2e database with a frozen clock', () => {
        const info = JSON.parse(artisan('e2e:whoami').trim().split('\n').pop() ?? '{}');
        expect(info.database).toBe('arovolife_e2e');
        expect(setClock('2026-06-15 10:30:00')).toContain('2026-06-15T10:30:00');
    });

    test('admin can sign in on :8085', async ({ page }) => {
        await page.goto('/login');
        await page.locator('input[name="login"]').fill('admin@arovolife.test');
        await page.locator('input[type="password"]').fill('admin12345');
        await page.locator('form').filter({ has: page.locator('input[type="password"]') }).evaluate((f) => f.submit());
        await page.waitForURL('**/admin**');
        await expect(page).toHaveURL(/\/admin/);
    });

    test('requests to other origins (internet and dev :8084) are blocked', async ({ page }) => {
        await page.goto('/');
        const blocked = await page.evaluate(async () => {
            const probe = (url) => fetch(url, { mode: 'no-cors' }).then(() => false, () => true);
            return [await probe('https://www.google-analytics.com/g/collect'), await probe('http://localhost:8084/login')];
        });
        expect(blocked).toEqual([true, true]);
    });
});

