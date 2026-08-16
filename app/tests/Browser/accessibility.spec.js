/**
 * T-5.6 — WCAG 2.1 AA scan.
 *
 * Runs axe-core against every page a distributor or a prospect can reach, plus
 * the admin screens staff live in all day. The exit gate asks for "Pa11y /
 * WCAG 2.1 AA scan + evidence"; axe-core is the engine Pa11y wraps, and driving
 * it through the Playwright suite that already exists here means the scan uses
 * the same logins, the same seeded data and the same base URL as every other
 * browser test rather than a second harness with its own fixtures.
 *
 * REQUIRES a dev dependency that is not yet installed:
 *
 *     npm i -D @axe-core/playwright
 *
 * It is deliberately not in package.json — adding dependencies needs approval
 * on this project. Until it is installed this file fails at import, which is
 * the honest outcome: a silently skipped accessibility scan reads exactly like
 * a passing one on a report.
 *
 * Run:  npx playwright test tests/Browser/accessibility.spec.js
 * Evidence: `--reporter=json > docs/compliance/evidence/a11y-<date>.json`
 *
 * A violation here is not automatically a launch blocker — axe reports some
 * things a human would waive — but every one of them has to be looked at and
 * either fixed or written down as accepted, with the reason.
 */

import AxeBuilder from '@axe-core/playwright';
import { test, expect } from './fixtures.js';

/** WCAG 2.1 AA is the target; the tags below are exactly that scope. */
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

/**
 * Scan one page and attach the full result to the test report, so the evidence
 * survives even when the assertion passes.
 */
async function scan(page, testInfo) {
    const results = await new AxeBuilder({ page }).withTags(TAGS).analyze();

    await testInfo.attach('axe-results.json', {
        body: JSON.stringify(results.violations, null, 2),
        contentType: 'application/json',
    });

    return results.violations;
}

/** Public pages — reachable by anyone, including a prospect mid-registration. */
const PUBLIC_PAGES = [
    ['home', '/'],
    ['login', '/login'],
    ['register', '/register'],
    ['shop', '/shop'],
    ['contact us', '/contact-us'],
    ['grievance form', '/grievance'],
    ['compensation plan', '/p/compensation'],
    ['privacy', '/p/privacy'],
    ['terms', '/p/terms'],
];

/** Distributor pages — the day-to-day surface. */
const DISTRIBUTOR_PAGES = [
    ['dashboard', '/dashboard'],
    ['Genos tree', '/tree/binary'],
    ['sponsorship tree', '/tree/sponsorship'],
    ['income dashboard', '/income'],
    ['wallet', '/wallet'],
    ['my orders', '/shop/orders'],
    ['profile', '/profile'],
    ['support', '/support'],
];

/** Admin pages — staff spend their whole day here, so they count too. */
const ADMIN_PAGES = [
    ['admin dashboard', '/admin'],
    ['distributors', '/admin/distributors'],
    ['KYC review', '/admin/kyc'],
    ['orders', '/admin/commerce/orders'],
    ['grievances', '/admin/grievances'],
    ['analytics', '/admin/analytics'],
    ['compensation', '/admin/compensation'],
];

test.describe('WCAG 2.1 AA — public', () => {
    for (const [name, path] of PUBLIC_PAGES) {
        test(`${name} has no WCAG 2.1 AA violations`, async ({ page }, testInfo) => {
            await page.goto(path);
            const violations = await scan(page, testInfo);
            expect(violations, violations.map(v => `${v.id}: ${v.help}`).join('\n')).toEqual([]);
        });
    }
});

test.describe('WCAG 2.1 AA — distributor', () => {
    for (const [name, path] of DISTRIBUTOR_PAGES) {
        test(`${name} has no WCAG 2.1 AA violations`, async ({ distributorPage: page }, testInfo) => {
            await page.goto(path);
            const violations = await scan(page, testInfo);
            expect(violations, violations.map(v => `${v.id}: ${v.help}`).join('\n')).toEqual([]);
        });
    }
});

test.describe('WCAG 2.1 AA — admin', () => {
    for (const [name, path] of ADMIN_PAGES) {
        test(`${name} has no WCAG 2.1 AA violations`, async ({ adminPage: page }, testInfo) => {
            await page.goto(path);
            const violations = await scan(page, testInfo);
            expect(violations, violations.map(v => `${v.id}: ${v.help}`).join('\n')).toEqual([]);
        });
    }
});
