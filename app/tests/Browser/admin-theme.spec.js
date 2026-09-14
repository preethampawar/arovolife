/**
 * Admin dark/light theme toggle — Browser Tests
 *
 * Plan: docs/plans/admin-dark-theme-2026-09-13.md
 *
 * Covers the whole contract of the feature:
 *   light is the default -> the header toggle flips the console -> the choice
 *   survives a reload and is applied before first paint -> a corrupt stored
 *   value degrades to light -> dark mode has no colour-contrast violations on
 *   both a card-heavy page (/admin) and a dense table (/admin/distributors)
 *   -> the toggle is unreachable for a distributor (inherited /admin gating).
 *
 * Requires:
 *   - App running at APP_URL (default http://localhost:8084)
 *   - admin@arovolife.test / admin12345
 *   - `npm i -D @axe-core/playwright` (already a devDependency)
 *
 * Every test restores localStorage['arovolife_theme'] before finishing:
 * the config runs `workers: 1, fullyParallel: false`, so a leaked dark theme
 * would follow whichever spec file runs next in the same storage context.
 *
 * Run:  npx playwright test tests/Browser/admin-theme.spec.js
 */

import AxeBuilder from '@axe-core/playwright';
import { test, expect } from './fixtures.js';

// One key for the whole application since the toggle left the console.
// The old admin-only key is still read once at boot as a fallback so an
// existing choice survives the rename.
const KEY = 'arovolife_theme';

const isDark = (page) => page.evaluate(() => document.documentElement.classList.contains('dark'));
const stored = (page) => page.evaluate((k) => localStorage.getItem(k), KEY);
const clearTheme = (page) => page.evaluate((k) => localStorage.removeItem(k), KEY);
const setTheme = (page, value) => page.evaluate(([k, v]) => localStorage.setItem(k, v), [KEY, value]);

const toggle = (page) => page.getByRole('button', { name: /toggle dark mode/i });

test.describe('admin theme toggle', () => {
    test('defaults to light with empty storage', async ({ adminPage: page }) => {
        await clearTheme(page);
        await page.reload();

        expect(await isDark(page)).toBe(false);
        await expect(toggle(page)).toHaveAttribute('aria-pressed', 'false');

        // The page surface is the light one, not merely "not dark".
        const bg = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
        expect(bg).toBe('rgb(243, 246, 250)');
    });

    test('clicking the toggle turns the console dark and records the choice', async ({ adminPage: page }) => {
        await clearTheme(page);
        await page.reload();

        await toggle(page).click();

        expect(await isDark(page)).toBe(true);
        expect(await stored(page)).toBe('dark');
        await expect(toggle(page)).toHaveAttribute('aria-pressed', 'true');
        expect(await page.evaluate(() => getComputedStyle(document.body).backgroundColor)).toBe('rgb(14, 22, 31)');

        await clearTheme(page);
    });

    test('the dark choice survives a reload and is applied before first paint', async ({ adminPage: page }) => {
        await setTheme(page, 'dark');
        await page.reload({ waitUntil: 'commit' });

        // `commit` resolves as soon as the navigation commits — the pre-paint
        // <head> script has run by the time the first DOM query lands, so a
        // class that only arrived with a DOMContentLoaded handler would fail
        // here. This is the FOUC assertion.
        await expect.poll(() => isDark(page)).toBe(true);

        await page.waitForLoadState('domcontentloaded');
        expect(await isDark(page)).toBe(true);

        await clearTheme(page);
    });

    test('toggling back returns to light and persists', async ({ adminPage: page }) => {
        await setTheme(page, 'dark');
        await page.reload();
        expect(await isDark(page)).toBe(true);

        await toggle(page).click();

        expect(await isDark(page)).toBe(false);
        expect(await stored(page)).toBe('light');

        await page.reload();
        expect(await isDark(page)).toBe(false);

        await clearTheme(page);
    });

    test('a corrupt stored value renders light and throws nothing', async ({ adminPage: page }) => {
        const errors = [];
        page.on('pageerror', (e) => errors.push(e.message));

        await setTheme(page, 'banana');
        await page.reload();

        expect(await isDark(page)).toBe(false);
        expect(errors).toEqual([]);

        await clearTheme(page);
    });

    test('the toggle is a real, named control in both states', async ({ adminPage: page }) => {
        await clearTheme(page);
        await page.reload();

        await expect(toggle(page)).toBeVisible();
        await expect(toggle(page)).toHaveAttribute('aria-pressed', 'false');

        await toggle(page).click();
        await expect(toggle(page)).toHaveAttribute('aria-pressed', 'true');

        // Exactly one of the two icons is rendered at a time.
        await expect(page.locator('[data-theme-icon="dark"]')).toBeVisible();
        await expect(page.locator('[data-theme-icon="light"]')).toBeHidden();

        await clearTheme(page);
    });
});

test.describe('dark mode contrast', () => {
    const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

    for (const [name, path] of [
        ['dashboard', '/admin'],
        ['distributors table', '/admin/distributors'],
    ]) {
        test(`${name} has no colour-contrast violations in dark mode`, async ({ adminPage: page }, testInfo) => {
            await setTheme(page, 'dark');
            await page.goto(path);
            expect(await isDark(page)).toBe(true);

            const results = await new AxeBuilder({ page })
                .withTags(TAGS)
                .withRules(['color-contrast'])
                .analyze();

            await testInfo.attach('axe-contrast.json', {
                body: JSON.stringify(results.violations, null, 2),
                contentType: 'application/json',
            });

            expect(results.violations).toEqual([]);

            await clearTheme(page);
        });
    }
});

test.describe('gating', () => {
    test('a distributor cannot reach the admin console or its toggle', async ({ distributorPage: page }) => {
        const response = await page.goto('/admin');

        expect(response?.status()).not.toBe(200);
        await expect(toggle(page)).toHaveCount(0);
    });
});
