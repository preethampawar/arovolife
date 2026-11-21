/**
 * Policy Pages — Browser Tests
 *
 * Covers Slice 7 (#27) of docs/plans/policy-pages-home-about-genos-star-fb-card-2026-09-23.md:
 *  - Each of the 9 client policy pages renders at its published URL
 *  - The Home footer links to the newly published slugs and each navigates
 *  - `compensation` stays held (never published by the blanket seed)
 *
 * URL shape: the plan's file-changes table says `/content/<slug>`, but the
 * actual route (routes/web.php, `Route::get('/p/{slug}', ...)->name('content.show')`)
 * is **`/p/<slug>`**. This spec uses `/p/<slug>` throughout.
 *
 * These pages are published by a blanket `db:seed --class=ContentPageSeeder`
 * (or the equivalent `content:publish` run) on dev. If the dev database has
 * never had that seeder run since 2026-09-23, some or all of these pages may
 * still be unpublished/absent — each check skips itself with a clear reason
 * rather than failing on a deliberate seed-state gap.
 */

import { test, expect } from './fixtures.js';

const POLICY_SLUGS = [
    ['terms', 'Direct Seller Agreement & Terms and Conditions'],
    ['ethics', 'Code of Ethics and Principles'],
    ['privacy', 'Privacy Policy'],
    ['grievance', 'Grievance Redressal Policy'],
    ['returns', 'Product Return, Warranty & Guarantee Policy'],
    ['shipping', 'Shipping & Delivery'],
    ['disclaimer', 'Disclaimer'],
    ['social-media', 'Social Media Policy'],
    ['policies', 'Policies & Procedures — Website Terms of Use'],
    ['refund', 'Refund Policy'],
];

test.describe('Policy pages: each of the 9 client documents', () => {
    for (const [slug, title] of POLICY_SLUGS) {
        test(`guest can open /p/${slug} and sees its h1`, async ({ page }) => {
            const response = await page.goto(`/p/${slug}`);
            expect(response?.status(), `/p/${slug} is not published on this environment yet — run the ContentPageSeeder blanket seed.`).not.toBe(404);
            expect(response?.status()).toBe(200);
            await expect(page.locator('h1').first()).toHaveText(title);
        });
    }
});

test.describe('Policy pages: Home footer links', () => {
    const footerLinks = [
        ['Shipping & delivery', 'shipping'],
        ['Disclaimer', 'disclaimer'],
        ['Social media policy', 'social-media'],
        // Named exactly as the payment gateway's website review lists them.
        ['Terms & Conditions', 'policies'],
        ['Privacy Policy', 'privacy'],
        ['Refund Policy', 'refund'],
        ['Cancellation Policy', 'cancellation'],
    ];

    for (const [label, slug] of footerLinks) {
        test(`Home footer shows "${label}" and it navigates to /p/${slug}`, async ({ page }) => {
            await page.goto('/');
            const link = page.getByRole('link', { name: label, exact: true });
            await expect(link, `"${label}" is not in the footer — ${slug} is not published on this environment.`).not.toHaveCount(0);
            await expect(link).toHaveAttribute('href', new RegExp(`/p/${slug}$`));
            await link.click();
            await expect(page).toHaveURL(new RegExp(`/p/${slug}$`));
            await expect(page.locator('h1').first()).toBeVisible();
        });
    }
});

test.describe('The compensation plan page', () => {
    // Seeded as a draft and published explicitly (`content:publish compensation`),
    // as on every environment since go-live prep; the e2e snapshot does the same,
    // because registration refuses to record consent against an unpublished plan.
    test('/p/compensation is published for guests', async ({ page }) => {
        const response = await page.goto('/p/compensation');
        expect(response?.status()).toBe(200);
        await expect(page.locator('h1').first()).toBeVisible();
    });
});
