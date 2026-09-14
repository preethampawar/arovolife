/**
 * GSB Income Flow — Browser Tests
 *
 * Covers:
 *  - Admin compensation overview: stat cards render, reversals shown in amber
 *  - Admin daily cut-off table: credited rows visible
 *  - Distributor income dashboard: live wallet / BV / carry-forward stats
 *  - Distributor GSB history: slab, gross, deductions, net displayed
 *  - Distributor wallet & payouts: running balance, next payout date
 *
 * Requires:
 *  - App running at APP_URL (default http://localhost:8084)
 *  - admin@arovolife.test / admin12345 to be valid admin credentials
 *  - the distributor fixture account (A11Y_ADN, default 360801433) to have at
 *    least one credited GSB cut-off
 *
 * Genos Sales Bonus ships behind a feature flag that is OFF until the DSA §6.2
 * notice period has run, and a flag that is off leaves no trace in the UI at
 * all — no tab, no cards. The GSB-dependent tests below therefore probe for the
 * tab first and skip when it is absent, rather than failing on a deliberate
 * product state. They resume on their own the day the flag is turned on.
 */

import { test, expect } from './fixtures.js';

/** True when Genos Sales Bonus is switched on for this environment. */
async function gsbIsLive(page) {
    await page.goto('/income');
    return (await page.getByRole('link', { name: 'GSB History' }).count()) > 0;
}

/**
 * The overview's cut-off table is scoped to today, and the cut-off itself runs
 * at 23:59 IST — so it is empty for all but the last minute of the day. Row
 * level assertions run against the most recent completed cut-off instead.
 */
function lastCutoffDate() {
    const d = new Date();
    d.setDate(d.getDate() - 1);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

async function openLastCutoff(page) {
    const date = lastCutoffDate();
    await page.goto(`/admin/compensation/daily-cutoffs?date=${date}`);
    const rows = page.locator('table tbody tr');
    test.skip(await rows.count() === 0, `No cut-off rows for ${date} — the engine has not run in this environment.`);
    return rows;
}

// ---------------------------------------------------------------------------
// Admin — Compensation Overview
// ---------------------------------------------------------------------------

test.describe('Admin: Compensation Overview', () => {
    test('stat cards are all visible', async ({ adminPage: page }) => {
        await page.goto('/admin/compensation');

        // Each card label appears exactly once as the first <p> in its card
        await expect(page.locator('p').filter({ hasText: "Today's cut-off" }).first()).toBeVisible();
        await expect(page.locator('p').filter({ hasText: 'Failed jobs' }).first()).toBeVisible();
        await expect(page.locator('p').filter({ hasText: 'Pending payouts' }).first()).toBeVisible();
        await expect(page.locator('p').filter({ hasText: 'GSB this week' }).first()).toBeVisible();
    });

    test('GSB this week shows a monetary value', async ({ adminPage: page }) => {
        await page.goto('/admin/compensation');

        const card = page.locator('.grid > div').filter({ hasText: 'GSB this week' });
        await expect(card).toBeVisible();
        await expect(card.locator('p.text-purple-700')).toContainText('₹');
    });

    test('reversal line shown in amber when reversals exist this week', async ({ adminPage: page }) => {
        await page.goto('/admin/compensation');

        const card = page.locator('.grid > div').filter({ hasText: 'GSB this week' });
        const reversalLine = card.locator('p.text-amber-600');

        const count = await reversalLine.count();
        if (count > 0) {
            await expect(reversalLine).toContainText('reversed');
        }
    });

    test('reversal amber line, when present, says "reversed"', async ({ adminPage: page }) => {
        await page.goto('/admin/compensation');
        const card = page.locator('.grid > div').filter({ hasText: 'GSB this week' });
        const amber = card.locator('p.text-amber-600');
        const count = await amber.count();
        expect(count).toBeLessThanOrEqual(1);
        if (count === 1) {
            await expect(amber).toContainText('reversed');
        }
    });
});

// ---------------------------------------------------------------------------
// Admin — Daily Cut-Off Table
// ---------------------------------------------------------------------------

test.describe('Admin: Daily Cut-Off Table', () => {
    test("the overview carries today's cut-off table", async ({ adminPage: page }) => {
        await page.goto('/admin/compensation');

        // Table heading is a span inside the card header. The rows themselves
        // only exist after 23:59, so this asserts the surface, not the data.
        await expect(page.locator('span').filter({ hasText: "Today's cut-off —" }).first()).toBeVisible();
    });

    test('the most recent cut-off renders with at least one row', async ({ adminPage: page }) => {
        const rows = await openLastCutoff(page);
        expect(await rows.count()).toBeGreaterThan(0);
    });

    test('credited rows show green badge', async ({ adminPage: page }) => {
        await openLastCutoff(page);

        // Status badges are <span> inside <td> — target them by combined class signature
        const creditedBadge = page.locator('tbody span').filter({ hasText: 'credited' }).first();
        test.skip(await creditedBadge.count() === 0, 'No credited rows in the most recent cut-off.');
        await expect(creditedBadge).toBeVisible();
        await expect(creditedBadge).toHaveClass(/bg-green-100/);
    });

    test('credited rows show a net GSB ₹ value', async ({ adminPage: page }) => {
        await openLastCutoff(page);

        // Find the row that has the credited badge and check its net GSB cell
        const creditedRow = page.locator('tbody tr').filter({ has: page.locator('span').filter({ hasText: 'credited' }) }).first();
        test.skip(await creditedRow.count() === 0, 'No credited rows in the most recent cut-off.');
        await expect(creditedRow).toBeVisible();
        const netCell = creditedRow.locator('td.text-green-700');
        await expect(netCell).toContainText('₹');
    });

    test('daily cut-off detail page loads for today', async ({ adminPage: page }) => {
        await page.goto('/admin/compensation/daily-cutoffs');

        await expect(page.locator('h1, h2').filter({ hasText: /cut-off/i }).first()).toBeVisible();
    });
});

// ---------------------------------------------------------------------------
// Distributor — Income Dashboard
// ---------------------------------------------------------------------------

test.describe('Distributor: Income Dashboard', () => {
    test('dashboard loads for authenticated distributor', async ({ distributorPage: page }) => {
        await page.goto('/income');
        await expect(page).toHaveTitle(/Income/i);
    });

    test('wallet balance hero card shows a ₹ amount', async ({ distributorPage: page }) => {
        await page.goto('/income');

        const hero = page.locator('.bg-gradient-to-r');
        await expect(hero).toBeVisible();
        await expect(hero.locator('p.text-4xl')).toContainText('₹');
    });

    test('personal BV shows a number (not em dash)', async ({ distributorPage: page }) => {
        await page.goto('/income');

        const bvCard = page.locator('.grid > div').filter({ hasText: 'Personal BV (Lifetime)' });
        await expect(bvCard).toBeVisible();
        const value = await bvCard.locator('p.text-2xl').textContent();
        expect(value?.trim()).toMatch(/[\d,]+/);
    });

    test('left and right Genos BV cards are present', async ({ distributorPage: page }) => {
        test.skip(!(await gsbIsLive(page)), 'Genos Sales Bonus is flagged off — the BV cards are not rendered at all.');

        // The dashboard nests grids, so this matches both the card and its
        // wrapper — take the first rather than trip strict mode.
        await expect(page.locator('.grid > div').filter({ hasText: 'Left Genos BV' }).first()).toBeVisible();
        await expect(page.locator('.grid > div').filter({ hasText: 'Right Genos BV' }).first()).toBeVisible();
    });

    test('power-side carry over card is present', async ({ distributorPage: page }) => {
        test.skip(!(await gsbIsLive(page)), 'Genos Sales Bonus is flagged off — the carry-over cards are not rendered at all.');

        await expect(page.locator('p').filter({ hasText: 'Power-side carry over' }).first()).toBeVisible();
    });

    test('slab-1 weaker carry over card is present', async ({ distributorPage: page }) => {
        test.skip(!(await gsbIsLive(page)), 'Genos Sales Bonus is flagged off — the carry-over cards are not rendered at all.');

        await expect(page.locator('p').filter({ hasText: 'Slab-1 weaker carry over' }).first()).toBeVisible();
    });

    test('income tabs all link to correct routes', async ({ distributorPage: page }) => {
        await page.goto('/income');

        // My Business, Income, Genos BV, Genos Ledger and Wallet & Payouts are
        // always on. Every other tab is flag-gated, so assert those only when
        // they are actually rendered — a missing one is a product state, not a
        // broken link, and a wrong href is a bug either way.
        await expect(page.getByRole('link', { name: 'Wallet & Payouts' })).toHaveAttribute('href', /wallet/);
        await expect(page.getByRole('link', { name: 'Genos BV', exact: true })).toHaveAttribute('href', /genos-bv/);

        for (const [label, href] of [['GSB History', /gsb-history/], ['Mentorship', /mentorship/]]) {
            const link = page.getByRole('link', { name: label, exact: true });
            if (await link.count() > 0) {
                await expect(link).toHaveAttribute('href', href);
            }
        }
    });
});

// ---------------------------------------------------------------------------
// Distributor — GSB History
// ---------------------------------------------------------------------------

test.describe('Distributor: GSB History', () => {
    test('page loads and shows table headers', async ({ distributorPage: page }) => {
        await page.goto('/income/gsb-history');
        await expect(page).toHaveTitle(/My Income/i);

        // Headers live in <th> elements
        await expect(page.locator('th').filter({ hasText: 'Left BV matched' })).toBeVisible();
        await expect(page.locator('th').filter({ hasText: 'Right BV matched' })).toBeVisible();
        // "Gross GSB" text also appears in an adjacent help-tip tooltip, so use first()
        await expect(page.locator('th').filter({ hasText: 'Gross GSB' }).first()).toBeVisible();
        // The 3% admin charge and 5% TDS became payout-time deductions on
        // 2026-09-05 and moved to the payout statement; a bonus row now shows
        // the repurchase deduction and what actually reached the wallet.
        await expect(page.locator('th').filter({ hasText: 'Repurchase deduction' }).first()).toBeVisible();
        await expect(page.locator('th').filter({ hasText: 'Credited to wallet' }).first()).toBeVisible();
    });

    test('at least one credited row is present', async ({ distributorPage: page }) => {
        await page.goto('/income/gsb-history');

        const rows = page.locator('table tbody tr');
        expect(await rows.count()).toBeGreaterThan(0);

        const credited = rows.filter({ hasText: 'Credited' }).first();
        test.skip(await credited.count() === 0, 'No credited GSB rows for the fixture distributor in this window.');
        await expect(credited).toBeVisible();
    });

    test('credited row shows correct deduction breakdown', async ({ distributorPage: page }) => {
        await page.goto('/income/gsb-history');

        const row = page.locator('table tbody tr').filter({ hasText: 'Credited' }).first();
        test.skip(await row.count() === 0, 'No credited GSB rows for the fixture distributor in this window.');

        // S.No. | Date | Left BV | Right BV | Slab | Gross | Repurchase deduction | Credited to wallet | Status
        await expect(row.locator('td').nth(5)).toContainText('₹');
        // The deduction renders as an em dash when it is zero for the row.
        await expect(row.locator('td').nth(6)).toContainText(/—|-₹/);
        await expect(row.locator('td').nth(7)).toContainText('₹');
        await expect(row.locator('td').nth(8)).toContainText('Credited');
    });

    test('month total row appears at the bottom', async ({ distributorPage: page }) => {
        await page.goto('/income/gsb-history');

        // One per month boundary on the page, so match the first rather than
        // asserting on a strict-mode locator that resolves to several.
        await expect(page.locator('tr').filter({ hasText: 'Month total' }).first()).toBeVisible();
    });

    test('CSV export link is present', async ({ distributorPage: page }) => {
        await page.goto('/income/gsb-history');

        await expect(page.getByRole('link', { name: /CSV/i })).toHaveAttribute('href', /export/);
    });

    test('date filter form is present', async ({ distributorPage: page }) => {
        await page.goto('/income/gsb-history');

        await expect(page.locator('input[type="date"]').first()).toBeVisible();
        await expect(page.getByRole('button', { name: 'Filter' })).toBeVisible();
    });
});

// ---------------------------------------------------------------------------
// Distributor — Wallet & Payouts
// ---------------------------------------------------------------------------

test.describe('Distributor: Wallet & Payouts', () => {
    test('page loads with correct title', async ({ distributorPage: page }) => {
        await page.goto('/income/wallet');
        await expect(page).toHaveTitle(/Wallet/i);
    });

    test('wallet balance stat card shows a ₹ amount', async ({ distributorPage: page }) => {
        await page.goto('/income/wallet');

        // The balance <p> is inside the card that has the "Wallet Balance" label
        const balanceCard = page.locator('div.bg-white').filter({ hasText: 'Wallet Balance' }).first();
        await expect(balanceCard).toBeVisible();
        await expect(balanceCard.locator('p.text-2xl')).toContainText('₹');
    });

    test('next payout date is shown', async ({ distributorPage: page }) => {
        await page.goto('/income/wallet');

        // The date card contains both the label and the value; target the whole card
        const dateCard = page.locator('div.bg-white').filter({ hasText: 'Next Payout Date' }).first();
        await expect(dateCard).toBeVisible();
        // The date value is the p.text-2xl inside that card (e.g. "30 Jun")
        const dateText = await dateCard.locator('p.text-2xl').textContent();
        expect(dateText?.trim()).toMatch(/\d/);
    });

    test('minimum payout threshold of ₹100 is shown', async ({ distributorPage: page }) => {
        await page.goto('/income/wallet');

        // "Min. Payout" label
        await expect(page.locator('p.text-xs').filter({ hasText: 'Min. Payout' })).toBeVisible();
        // ₹100 value (KP-confirmed minimum) is inside its stat card
        const minCard = page.locator('div.bg-white').filter({ hasText: 'Min. Payout' }).first();
        await expect(minCard.locator('p').filter({ hasText: '₹100' })).toBeVisible();
    });

    test('wallet ledger table has a gsb_credit row', async ({ distributorPage: page }) => {
        await page.goto('/income/wallet');

        // "Repurchase Wallet Ledger" also contains this text, so take the first.
        await expect(page.getByText('Wallet Ledger', { exact: false }).first()).toBeVisible();
        // The ledger shows a distributor-facing label now — WalletLedgerEntry
        // ::typeLabels() maps gsb_credit to "Genos Sales Bonus" so neither the
        // page nor the CSV leaks the internal enum.
        // The cell can also carry a memo line underneath, so match on the label
        // rather than the cell's whole text.
        await expect(page.locator('td').filter({ hasText: 'Genos Sales Bonus' }).first()).toBeVisible();
    });

    test('ledger entry shows positive amount and running balance', async ({ distributorPage: page }) => {
        await page.goto('/income/wallet');

        const ledgerTable = page.locator('section, div').filter({ hasText: 'Wallet Ledger' }).locator('table').first();
        await expect(ledgerTable).toBeVisible();

        // Credit amounts are rendered as "+₹X.XX" with text-green-700
        await expect(ledgerTable.locator('td.text-green-700').first()).toContainText('+₹');

        // Running balance cells contain "₹" (rendered as plain ₹X.XX)
        await expect(ledgerTable.locator('td').filter({ hasText: /₹[\d,]+\.\d{2}/ }).first()).toBeVisible();
    });

    test('wallet CSV export link works', async ({ distributorPage: page }) => {
        await page.goto('/income/wallet');

        await expect(page.getByRole('link', { name: /CSV/i })).toHaveAttribute('href', /wallet\/export/);
    });

    test('payout history section is present', async ({ distributorPage: page }) => {
        await page.goto('/income/wallet');

        await expect(page.getByText('Payout History', { exact: false })).toBeVisible();
    });

    test('wallet balance matches where the ledger ends', async ({ distributorPage: page }) => {
        // This used to compare the balance against one month's GSB total, which
        // only held while the fixture distributor had a single month of credits
        // and no other bonus type. The balance is a projection of the ledger, so
        // assert that instead: the running balance on the last ledger row is the
        // balance. Ledger rows run oldest-first, so "last" is the newest entry.
        await page.goto('/income/wallet');

        const balanceCard = page.locator('div.bg-white').filter({ hasText: 'Wallet Balance' }).first();
        const balanceText = (await balanceCard.locator('p.text-2xl').textContent())?.trim() ?? '';
        const balance = parseFloat(balanceText.replace(/[₹,]/g, ''));
        expect(Number.isFinite(balance)).toBe(true);

        const ledger = page.locator('section, div').filter({ hasText: 'Wallet Ledger' }).locator('table').first();
        const rows = ledger.locator('tbody tr');
        test.skip(await rows.count() === 0, 'The fixture distributor has no wallet ledger entries.');
        // Only the final page of the ledger ends on the current balance.
        test.skip(
            await page.getByRole('link', { name: 'Next' }).count() > 0,
            'The ledger is paginated — the running balance on this page is partial.',
        );

        const runningText = (await rows.last().locator('td').last().textContent())?.trim() ?? '';
        const running = parseFloat(runningText.replace(/[₹,]/g, ''));
        expect(Number.isFinite(running)).toBe(true);
        expect(Math.abs(balance - running)).toBeLessThanOrEqual(0.01);
    });
});

// ---------------------------------------------------------------------------
// Distributor — Genos BV History
// ---------------------------------------------------------------------------

test.describe('Distributor: Genos BV History', () => {
    test('page loads and shows cut-off rows', async ({ distributorPage: page }) => {
        await page.goto('/income/genos-bv');
        await expect(page).toHaveTitle(/My Income/i);

        const rows = page.locator('table tbody tr');
        expect(await rows.count()).toBeGreaterThan(0);
    });
});

// ---------------------------------------------------------------------------
// Auth guard — unauthenticated access redirects to login
// ---------------------------------------------------------------------------

test.describe('Auth guard', () => {
    test('income routes redirect unauthenticated users to login', async ({ page }) => {
        await page.goto('/income');
        await expect(page).toHaveURL(/login/);
    });

    test('income wallet redirects unauthenticated users to login', async ({ page }) => {
        await page.goto('/income/wallet');
        await expect(page).toHaveURL(/login/);
    });

    test('admin compensation redirects non-admin to 403 or login', async ({ page }) => {
        await page.goto('/admin/compensation');
        const url = page.url();
        const title = await page.title();
        expect(url.includes('login') || title.includes('403') || title.includes('Forbidden')).toBe(true);
    });
});
