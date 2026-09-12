/**
 * Action Center — Browser Tests (slice A7)
 *
 * Walks the admin Action Center UI built in A5
 * (app/Modules/ActionCenter/Http/Controllers/Admin/AdminActionCenterController.php):
 *   sidebar link + critical badge -> dashboard card -> index (group cards) ->
 *   type page (filters, "Fix ->" deep link, snooze) -> statutory type has no
 *   snooze control -> non-admin permission check.
 *
 * Requires:
 *   - App running at APP_URL (default http://localhost:8084)
 *   - admin@arovolife.test / admin12345 (super-staff — bypasses every
 *     permission via Gate::before, so it sees every provider)
 *
 * Notes:
 *   - The dev database may or may not have rows for any given provider type
 *     at test time (providers read live operational tables, nothing is
 *     seeded for them). Every assertion that depends on a specific type
 *     having outstanding items skips with a message naming exactly what is
 *     missing, rather than passing on an empty page by accident.
 *   - `orders.paid_not_packed` (label "Paid orders not packed") is the
 *     non-statutory type used for the snooze test; `orders.invoice_missing`
 *     (label "Paid orders missing an invoice") is statutory (plan §5) and is
 *     used to assert the snooze control is absent.
 *   - The snooze form carries both `data-confirm` and `data-confirm-impact`,
 *     so `submitAndConfirm()` from fixtures.js is used even though this one
 *     always shows the modal (per fixtures.js's own docs on the two cases).
 *   - fixtures.js only exposes an admin login and a distributor login (no
 *     scoped admin-operations/-finance/-compliance role), so the permission
 *     test here is "a distributor cannot reach the admin Action Center" via
 *     the route's `role:developer|admin|admin-operations|admin-finance|
 *     admin-compliance` middleware (routes/web.php) — the closest check
 *     these fixtures allow. A distributor never holds any admin-family role.
 */

import { test, expect, submitAndConfirm } from './fixtures.js';

const NON_STATUTORY_TYPE = 'orders.paid_not_packed';
const NON_STATUTORY_LABEL = 'Paid orders not packed';
const STATUTORY_TYPE = 'orders.invoice_missing';
const STATUTORY_LABEL = 'Paid orders missing an invoice';

test.describe('Action Center: sidebar and dashboard', () => {
    test('sidebar shows an Action Center link for the admin', async ({ adminPage: page }) => {
        await page.goto('/admin');
        await expect(page.getByRole('link', { name: 'Action Center', exact: true })).toBeVisible();
    });

    test('sidebar badge shows only when the viewer has a critical count', async ({ adminPage: page }) => {
        await page.goto('/admin');
        const link = page.getByRole('link', { name: 'Action Center', exact: true });
        await expect(link).toBeVisible();
        const badge = link.locator('.admin-nav-badge');
        const badgeCount = await badge.count();

        if (badgeCount === 0) {
            // Zero criticals renders no badge at all (plan §10.5) — nothing
            // further to assert for this admin session right now.
            return;
        }

        const text = (await badge.first().textContent())?.trim() ?? '';
        const n = parseInt(text, 10);
        expect(Number.isFinite(n)).toBeTruthy();
        expect(n).toBeGreaterThan(0);
    });

    test('dashboard shows the Action Center card only when there are critical items, and it links through', async ({ adminPage: page }) => {
        await page.goto('/admin');
        const heading = page.getByRole('heading', { name: 'Action Center — oldest critical items' });

        if ((await heading.count()) === 0) {
            test.skip(true, 'No critical Action Center items in the dev database right now — dashboard card is not rendered.');
        }

        await expect(heading).toBeVisible();
        const card = page.locator('div').filter({ has: heading }).first();
        const viewAll = card.getByRole('link', { name: 'View all →' });
        await expect(viewAll).toBeVisible();
        await viewAll.click();
        await page.waitForURL('**/admin/action-center');
        await expect(page.getByRole('heading', { name: 'Action Center', exact: true })).toBeVisible();
    });
});

test.describe('Action Center: index', () => {
    test('index page renders group cards for an admin', async ({ adminPage: page }) => {
        await page.goto('/admin/action-center');
        await expect(page.getByRole('heading', { name: 'Action Center', exact: true })).toBeVisible();

        const empty = page.getByText('Nothing needs action right now.', { exact: true });
        if (await empty.isVisible().catch(() => false)) {
            test.skip(true, 'No Action Center items of any type in the dev database right now.');
        }

        // At least one of the six group cards (plan §4 catalogue) is present.
        const groupLabels = ['Orders & fulfilment', 'Stock', 'Money', 'People', 'Compliance', 'Platform'];
        const visibleGroups = [];
        for (const label of groupLabels) {
            if (await page.getByRole('heading', { name: label, exact: true }).isVisible().catch(() => false)) {
                visibleGroups.push(label);
            }
        }
        expect(visibleGroups.length).toBeGreaterThan(0);
    });

    test('clicking a group row opens that type\'s page', async ({ adminPage: page }) => {
        await page.goto('/admin/action-center');

        const row = page.getByRole('link', { name: new RegExp(NON_STATUTORY_LABEL) }).first();
        test.skip((await row.count()) === 0, `"${NON_STATUTORY_LABEL}" is not on the index right now — no outstanding items of that type.`);

        await row.click();
        await page.waitForURL(`**/admin/action-center/${NON_STATUTORY_TYPE}`);
        await expect(page.getByRole('heading', { name: NON_STATUTORY_LABEL, exact: true })).toBeVisible();
    });
});

test.describe('Action Center: type page', () => {
    test('lists items with severity, age and a working "Fix ->" deep link', async ({ adminPage: page }) => {
        await page.goto(`/admin/action-center/${NON_STATUTORY_TYPE}`);
        await expect(page.getByRole('heading', { name: NON_STATUTORY_LABEL, exact: true })).toBeVisible();

        const emptyRow = page.locator('td').filter({ hasText: 'Nothing needs action right now.' });
        test.skip(await emptyRow.isVisible().catch(() => false), `No "${NON_STATUTORY_LABEL}" items in the dev database right now.`);

        const firstRow = page.locator('tbody tr').first();
        await expect(firstRow.locator('span').filter({ hasText: /^(Critical|Warning|Info)$/ }).first()).toBeVisible();
        // Age column: a number of hours, e.g. "72h".
        await expect(firstRow.locator('td').nth(2)).toHaveText(/^\d+h$/);

        const fixLink = firstRow.getByRole('link', { name: 'Fix →' });
        test.skip((await fixLink.count()) === 0, 'The first row has no "Fix →" link (item.url is null for this provider/row).');

        const href = await fixLink.getAttribute('href');
        await fixLink.click();
        await page.waitForURL('**/admin/commerce/orders/**');
        expect(page.url()).toContain(href.split('/').pop());
        // Lands on the existing order screen, not a 404.
        await expect(page.locator('body')).not.toContainText('404');
    });

    test('filters narrow the list', async ({ adminPage: page }) => {
        await page.goto(`/admin/action-center/${NON_STATUTORY_TYPE}`);

        const emptyRow = page.locator('td').filter({ hasText: 'Nothing needs action right now.' });
        test.skip(await emptyRow.isVisible().catch(() => false), `No "${NON_STATUTORY_LABEL}" items in the dev database right now — cannot exercise filters.`);

        const rowCountBefore = await page.locator('tbody tr').count();

        // Filter by a severity value guaranteed not to overlap with every row
        // — pick whichever severity the first row does NOT have.
        const firstBadgeText = (await page.locator('tbody tr').first().locator('span').filter({ hasText: /^(Critical|Warning|Info)$/ }).first().textContent())?.trim();
        const otherSeverity = firstBadgeText === 'Critical' ? 'warning' : 'critical';

        await page.selectOption('select[name="severity"]', otherSeverity);
        await page.getByRole('button', { name: 'Filter' }).click();
        await page.waitForURL(new RegExp(`severity=${otherSeverity}`));

        const rowsAfter = page.locator('tbody tr');
        const afterCount = await rowsAfter.count();
        const afterIsEmpty = await page.locator('td').filter({ hasText: 'Nothing needs action right now.' }).isVisible().catch(() => false);

        if (!afterIsEmpty) {
            for (let i = 0; i < afterCount; i++) {
                await expect(rowsAfter.nth(i).locator('span').filter({ hasText: new RegExp(`^${otherSeverity === 'critical' ? 'Critical' : 'Warning'}$`) })).toBeVisible();
            }
        }
        expect(afterCount <= rowCountBefore || afterIsEmpty).toBeTruthy();

        // Clearing the filter returns to the unfiltered page.
        await page.getByRole('link', { name: 'Clear' }).click();
        await page.waitForURL(`**/admin/action-center/${NON_STATUTORY_TYPE}`);
    });

    test('snoozing an item with a reason removes it from the list and drops the count', async ({ adminPage: page }) => {
        await page.goto(`/admin/action-center/${NON_STATUTORY_TYPE}`);

        const emptyRow = page.locator('td').filter({ hasText: 'Nothing needs action right now.' });
        test.skip(await emptyRow.isVisible().catch(() => false), `No "${NON_STATUTORY_LABEL}" items in the dev database right now — nothing to snooze.`);

        const countBefore = await page.locator('tbody tr').count();
        const firstRow = page.locator('tbody tr').first();
        const subtitle = (await firstRow.locator('td').nth(1).locator('p').last().textContent())?.trim() ?? '';

        const snoozeToggle = firstRow.getByText('Snooze', { exact: true });
        test.skip((await snoozeToggle.count()) === 0, 'The first row has no Snooze control — it may be a statutory type row (unexpected here) or already snoozed.');

        await snoozeToggle.click();
        const form = firstRow.locator('form[action*="/snooze"]');
        await form.locator('input[name="days"]').fill('3');
        await form.locator('textarea[name="reason"]').fill('Playwright browser test: verifying snooze removes the row.');
        await submitAndConfirm(page, form.getByRole('button', { name: 'Snooze' }));

        await page.waitForURL(`**/admin/action-center/${NON_STATUTORY_TYPE}`);
        await expect(page.getByText('Item snoozed.', { exact: true })).toBeVisible();

        const countAfter = await page.locator('tbody tr').count();
        const afterIsEmpty = await page.locator('td').filter({ hasText: 'Nothing needs action right now.' }).isVisible().catch(() => false);

        if (afterIsEmpty) {
            expect(countBefore).toBe(1);
        } else {
            expect(countAfter).toBeLessThan(countBefore);
            if (subtitle) {
                await expect(page.locator('tbody')).not.toContainText(subtitle);
            }
        }
    });

    test('a statutory type shows no snooze control', async ({ adminPage: page }) => {
        await page.goto(`/admin/action-center/${STATUTORY_TYPE}`);
        await expect(page.getByRole('heading', { name: STATUTORY_LABEL, exact: true })).toBeVisible();

        const emptyRow = page.locator('td').filter({ hasText: 'Nothing needs action right now.' });
        test.skip(await emptyRow.isVisible().catch(() => false), `No "${STATUTORY_LABEL}" items in the dev database right now.`);

        await expect(page.getByText('Snooze', { exact: true })).toHaveCount(0);
    });
});

test.describe('Action Center: permissions', () => {
    test('a distributor cannot reach the admin Action Center', async ({ distributorPage: page }) => {
        // No admin-family role (`developer|admin|admin-operations|admin-finance|
        // admin-compliance`, routes/web.php) is ever assigned to a distributor,
        // so the route's role middleware refuses the whole /admin prefix
        // before the Action Center's own `action.center.view` gate is reached.
        const response = await page.goto('/admin/action-center');
        expect(response?.status()).not.toBe(200);
        await expect(page.getByRole('heading', { name: 'Action Center', exact: true })).toHaveCount(0);
    });
});
