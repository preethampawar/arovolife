/**
 * Home & About Content Merge — Browser Tests
 *
 * Covers Slice 7 (#28) of docs/plans/policy-pages-home-about-genos-star-fb-card-2026-09-23.md:
 *  - Home: the new first hero slide, "The arovolife Promise" paragraph, and
 *    the registration steps including Orientation (hard rule 4 — mandatory
 *    orientation must never be dropped from the step list)
 *  - About: the new "Wellness with Purpose" sub-heading, the untouched
 *    Compliance & trust heading, and no "recruit(ing|ment) alone" copy
 *    anywhere on the page (hard rule 2 — commissions are a function of
 *    product sales only, never recruitment alone)
 */

import { test, expect } from './fixtures.js';

test.describe('Home: content merge', () => {
    test('the new first hero slide heading is present', async ({ page }) => {
        await page.goto('/');
        await expect(page.getByText('Start Your Direct Selling Journey', { exact: false }).first()).toBeVisible();
    });

    test('"The arovolife Promise" is visible', async ({ page }) => {
        await page.goto('/');
        await expect(page.getByText('The arovolife Promise', { exact: true }).first()).toBeVisible();
    });

    test('the registration steps include Orientation', async ({ page }) => {
        await page.goto('/');
        await expect(page.getByText('Orientation', { exact: true }).first()).toBeVisible();
    });
});

test.describe('About: content merge', () => {
    test('"Wellness with Purpose" sub-heading is visible', async ({ page }) => {
        await page.goto('/about-us');
        await expect(page.getByText('Wellness with Purpose', { exact: false }).first()).toBeVisible();
    });

    test('the existing "The law isn\'t a checklist for us" heading is unchanged', async ({ page }) => {
        await page.goto('/about-us');
        await expect(page.getByText("The law isn't a checklist for us", { exact: false }).first()).toBeVisible();
    });

    test('no text on the page matches /recruit(ing|ment) alone/i (hard rule 2)', async ({ page }) => {
        await page.goto('/about-us');
        const bodyText = await page.locator('body').innerText();
        expect(bodyText).not.toMatch(/recruit(ing|ment) alone/i);
    });
});
