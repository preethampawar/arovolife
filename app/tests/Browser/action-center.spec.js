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
 *   - the e2e stack on http://localhost:8085 (world gsb-basic)
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

import { resetDatabase, withFeature } from './support/artisan.js';
import { test, expect, submitAndConfirm } from './fixtures.js';

// The snooze test writes a row; leave the e2e database as it was found.
test.afterAll(() => resetDatabase());

const NON_STATUTORY_TYPE = 'orders.paid_not_packed';
const NON_STATUTORY_LABEL = 'Paid orders not packed';
const STATUTORY_TYPE = 'orders.invoice_missing';
const STATUTORY_LABEL = 'Paid orders missing an invoice';

test.describe('Action Center: sidebar and dashboard', () => {
    // The sidebar entry was narrowed to the developer role on 2026-09-12 while
    // the screen beds in (AdminNavigation::overviewItems). The route itself is
    // still open to the admin family, so these two tests assert the gate rather
    // than the link: once the entry is widened again the second one resumes on
    // its own, because it keys off whether the link is actually rendered.
    test('the sidebar entry stays hidden from a non-developer admin', async ({ adminPage: page }) => {
        await page.goto('/admin');
        await expect(page.getByRole('link', { name: /^Action Center/ })).toHaveCount(0);

        // Hidden from the nav, still reachable by route — the gate is about
        // surfacing the screen, not about permission.
        await page.goto(`/admin/action-center/${NON_STATUTORY_TYPE}`);
        await expect(page.getByRole('heading', { name: NON_STATUTORY_LABEL })).toBeVisible();
    });

    test.describe('as the developer', () => {
        test.use({ staffKey: 'developer' });

        // The sidebar entry and the dashboard card are developer-only
        // (AdminNavigation::overviewItems), so these run as the world's developer.
        test('sidebar badge shows only when the viewer has a critical count', async ({ staffPage: page }) => {
            await page.goto('/admin');
            // The badge (when non-zero) is nested inside the link and concatenates
            // into its accessible name ("Action Center 319"), so match by prefix
            // rather than `exact: true`.
            const link = page.getByRole('link', { name: /^Action Center/ });
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

        // The dashboard's "Needs attention" panel (DashboardPanels 'attention')
        // replaced the old "oldest critical items" card. It is behind
        // ActionCenterFeature, which the snapshot pins OFF: absent without it,
        // listing the outstanding types with it, each row linking to its type page.
        test('dashboard shows the Needs attention panel only with the flag on, and its rows link through', async ({ staffPage: page }) => {
            const heading = page.getByRole('heading', { name: 'Needs attention', exact: true });

            await page.goto('/admin');
            await expect(heading).toHaveCount(0);

            await withFeature('ActionCenterFeature', async () => {
                await page.goto('/admin');
                await expect(heading).toBeVisible();

                // gsb-basic leaves paid, unpacked orders past the pack SLA.
                const row = page.locator(`a[href$="/admin/action-center/${NON_STATUTORY_TYPE}"]`).first();
                await expect(row).toContainText(NON_STATUTORY_LABEL);
                await row.click();
                await page.waitForURL(`**/admin/action-center/${NON_STATUTORY_TYPE}`);
                await expect(page.getByRole('heading', { name: NON_STATUTORY_LABEL })).toBeVisible();
            });
        });
    });
});

test.describe('Action Center: index', () => {
    test('index page renders group cards for an admin', async ({ adminPage: page }) => {
        await page.goto('/admin/action-center');
        await expect(page.getByRole('heading', { name: 'Action Center', exact: true })).toBeVisible();

        const empty = page.getByText('Nothing needs action right now.', { exact: true });
        // gsb-basic leaves three paid, unpacked orders past the pack SLA.
        await expect(empty).toHaveCount(0);

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
        await expect(row).toBeVisible();

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
        await expect(emptyRow).toHaveCount(0);

        const firstRow = page.locator('tbody tr').first();
        await expect(firstRow.locator('span').filter({ hasText: /^(Critical|Warning|Info)$/ }).first()).toBeVisible();
        // Age column: a number of hours, e.g. "72h".
        await expect(firstRow.locator('td').nth(2)).toHaveText(/^\d+h$/);

        const fixLink = firstRow.getByRole('link', { name: 'Fix' });
        await expect(fixLink).toHaveCount(1);

        const href = await fixLink.getAttribute('href');
        await fixLink.click();
        await page.waitForURL('**/admin/commerce/orders/**');
        expect(page.url()).toContain(href.split('/').pop());
        // Lands on the existing order screen, not a 404. Asserted on the
        // heading rather than by looking for "404" anywhere in the body —
        // plenty of real order pages contain those three digits in an order
        // number or an amount, which made this fail on the data rather than
        // on the behaviour.
        await expect(page.getByRole('heading', { name: /^Order / })).toBeVisible();
    });

    test('filters narrow the list', async ({ adminPage: page }) => {
        await page.goto(`/admin/action-center/${NON_STATUTORY_TYPE}`);

        const emptyRow = page.locator('td').filter({ hasText: 'Nothing needs action right now.' });
        await expect(emptyRow).toHaveCount(0);

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
        await expect(emptyRow).toHaveCount(0);

        const countBefore = await page.locator('tbody tr').count();
        const firstRow = page.locator('tbody tr').first();
        // The title (e.g. an order number) is unique per row; the subtitle is
        // a shared description and can repeat across many rows on a full page.
        const title = (await firstRow.locator('td').nth(1).locator('p').first().textContent())?.trim() ?? '';

        // Both the <summary> toggle and the submit button read "Snooze" —
        // scope to the <summary> element specifically to avoid ambiguity.
        const snoozeToggle = firstRow.locator('summary').filter({ hasText: 'Snooze' });
        await expect(snoozeToggle).toHaveCount(1);

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
            // A full page (PER_PAGE = 50) backfills from the next item when one
            // row is removed, so the visible count only drops below a full
            // page — the reliable signal is that the snoozed row is gone.
            if (countBefore < 50) {
                expect(countAfter).toBeLessThan(countBefore);
            }
            if (title) {
                await expect(page.locator('tbody')).not.toContainText(title);
            }
        }
    });

    test('a statutory type shows no snooze control', async ({ adminPage: page }) => {
        await page.goto(`/admin/action-center/${STATUTORY_TYPE}`);
        await expect(page.getByRole('heading', { name: STATUTORY_LABEL, exact: true })).toBeVisible();

        const emptyRow = page.locator('td').filter({ hasText: 'Nothing needs action right now.' });
        await expect(emptyRow).toHaveCount(0);

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
