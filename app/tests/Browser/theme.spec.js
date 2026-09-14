/**
 * App-wide light/dark theme.
 *
 * The console has had a theme toggle for a while; this covers the rest of the
 * application now that the same control, the same storage key and the same
 * pre-paint script serve the public site, the shop, the distributor portal and
 * the registration wizard.
 *
 * Three things are worth testing and one is not:
 *   - the control is present and actually flips the page, everywhere;
 *   - the choice is one choice — set it in the shop, it holds in the portal;
 *   - dark mode does not quietly break contrast, which is the failure mode a
 *     hand-written remap layer actually has.
 * What is NOT tested is that dark mode looks nice. That is a judgement, and a
 * screenshot diff of a gradient is a flake generator.
 */

import AxeBuilder from '@axe-core/playwright';
import { test, expect } from './fixtures.js';

const KEY = 'arovolife_theme';
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

const isDark = (page) => page.evaluate(() => document.documentElement.classList.contains('dark'));
const stored = (page) => page.evaluate((k) => localStorage.getItem(k), KEY);
const clearTheme = (page) => page.evaluate((k) => localStorage.removeItem(k), KEY);
const setTheme = (page, v) => page.evaluate(([k, val]) => localStorage.setItem(k, val), [KEY, v]);
const toggle = (page) => page.getByRole('button', { name: /toggle dark mode/i }).first();

/** Anonymous, signed-in distributor, and the wizard — one page each. */
const PUBLIC_PAGES = [['home', '/'], ['login', '/login'], ['shop', '/shop'], ['terms', '/p/terms']];
const DISTRIBUTOR_PAGES = [['dashboard', '/dashboard'], ['income', '/income'], ['wallet', '/income/wallet'], ['my orders', '/orders']];

test.describe('the toggle reaches the whole application', () => {
    for (const [name, path] of PUBLIC_PAGES) {
        test(`${name} carries a working toggle`, async ({ page }) => {
            await page.goto(path);
            await clearTheme(page);
            await page.reload();

            expect(await isDark(page)).toBe(false);
            await expect(toggle(page)).toBeVisible();

            await toggle(page).click();
            expect(await isDark(page)).toBe(true);
            expect(await stored(page)).toBe('dark');

            await clearTheme(page);
        });
    }

    test('the distributor portal carries a working toggle', async ({ distributorPage: page }) => {
        await page.goto('/dashboard');
        await clearTheme(page);
        await page.reload();

        expect(await isDark(page)).toBe(false);
        await toggle(page).click();
        expect(await isDark(page)).toBe(true);

        await clearTheme(page);
    });

    test('the registration wizard carries a working toggle', async ({ page }) => {
        await page.goto('/register');
        await clearTheme(page);
        await page.reload();

        await expect(toggle(page)).toBeVisible();
        await toggle(page).click();
        expect(await isDark(page)).toBe(true);

        await clearTheme(page);
    });

    test('one choice, not one per area', async ({ distributorPage: page }) => {
        // Chosen on the shop, honoured in the portal and the console alike:
        // the whole point of moving off the admin-only storage key.
        await page.goto('/shop');
        await setTheme(page, 'dark');

        for (const path of ['/dashboard', '/income', '/orders', '/admin']) {
            await page.goto(path);
            expect(await isDark(page), `${path} did not honour the stored choice`).toBe(true);
        }

        await clearTheme(page);
    });

    test('the OS preference is never consulted', async ({ page }) => {
        // A dark OS with no stored choice must still render light — the bug
        // this rule exists to prevent is a dark control on a light page.
        await page.emulateMedia({ colorScheme: 'dark' });
        await page.goto('/');
        await clearTheme(page);
        await page.reload();

        expect(await isDark(page)).toBe(false);
        await page.emulateMedia({ colorScheme: 'light' });
    });
});

test.describe('dark mode contrast', () => {
    for (const [name, path] of PUBLIC_PAGES) {
        test(`${name} has no new colour-contrast violations in dark mode`, async ({ page }, testInfo) => {
            await page.goto(path);
            await setTheme(page, 'dark');
            await page.goto(path);
            expect(await isDark(page)).toBe(true);

            const results = await new AxeBuilder({ page }).withTags(TAGS).withRules(['color-contrast']).analyze();
            await testInfo.attach('axe-contrast.json', {
                body: JSON.stringify(results.violations, null, 2),
                contentType: 'application/json',
            });
            expect(results.violations).toEqual([]);

            await clearTheme(page);
        });
    }

    for (const [name, path] of DISTRIBUTOR_PAGES) {
        test(`${name} has no colour-contrast violations in dark mode`, async ({ distributorPage: page }, testInfo) => {
            await page.goto(path);
            await setTheme(page, 'dark');
            await page.goto(path);
            expect(await isDark(page)).toBe(true);

            const results = await new AxeBuilder({ page }).withTags(TAGS).withRules(['color-contrast']).analyze();
            await testInfo.attach('axe-contrast.json', {
                body: JSON.stringify(results.violations, null, 2),
                contentType: 'application/json',
            });
            expect(results.violations).toEqual([]);

            await clearTheme(page);
        });
    }
});
