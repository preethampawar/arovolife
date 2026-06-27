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
 *  - D#59 (ADN 394325128) to exist with at least one credited GSB cutoff
 *  - admin@arovolife.test / admin12345 to be valid admin credentials
 *  - D#59 password to be Test1234! (reset in dev via tinker)
 */

import { test, expect } from './fixtures.js';

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
    test('today cut-off table renders with at least one row', async ({ adminPage: page }) => {
        await page.goto('/admin/compensation');

        // Table heading is a span inside the card header
        await expect(page.locator('span').filter({ hasText: "Today's cut-off —" }).first()).toBeVisible();

        const rows = page.locator('table tbody tr');
        expect(await rows.count()).toBeGreaterThan(0);
    });

    test('credited rows show green badge', async ({ adminPage: page }) => {
        await page.goto('/admin/compensation');

        // Status badges are <span> inside <td> — target them by combined class signature
        const creditedBadge = page.locator('tbody span').filter({ hasText: 'credited' }).first();
        await expect(creditedBadge).toBeVisible();
        await expect(creditedBadge).toHaveClass(/bg-green-100/);
    });

    test('credited rows show a net GSB ₹ value', async ({ adminPage: page }) => {
        await page.goto('/admin/compensation');

        // Find the row that has the credited badge and check its net GSB cell
        const creditedRow = page.locator('tbody tr').filter({ has: page.locator('span').filter({ hasText: 'credited' }) }).first();
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
        await expect(page).toHaveTitle(/My Income/i);
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

    test('left and right group BV cards are present', async ({ distributorPage: page }) => {
        await page.goto('/income');

        await expect(page.locator('.grid > div').filter({ hasText: 'Left Group BV' })).toBeVisible();
        await expect(page.locator('.grid > div').filter({ hasText: 'Right Group BV' })).toBeVisible();
    });

    test('power-side carry-forward card is present', async ({ distributorPage: page }) => {
        await page.goto('/income');

        await expect(page.locator('p').filter({ hasText: 'Power-side Carry-forward' }).first()).toBeVisible();
    });

    test('slab-1 weaker carry-forward card is present', async ({ distributorPage: page }) => {
        await page.goto('/income');

        await expect(page.locator('p').filter({ hasText: 'Slab-1 Weaker Carry-forward' }).first()).toBeVisible();
    });

    test('income tabs all link to correct routes', async ({ distributorPage: page }) => {
        await page.goto('/income');

        await expect(page.getByRole('link', { name: 'GSB History' })).toHaveAttribute('href', /gsb-history/);
        await expect(page.getByRole('link', { name: 'Wallet & Payouts' })).toHaveAttribute('href', /wallet/);
        await expect(page.getByRole('link', { name: 'Genos BV' })).toHaveAttribute('href', /genos-bv/);
        await expect(page.getByRole('link', { name: 'Mentorship' })).toHaveAttribute('href', /mentorship/);
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
        await expect(page.locator('th').filter({ hasText: 'Admin 3%' })).toBeVisible();
        await expect(page.locator('th').filter({ hasText: 'TDS 5%' })).toBeVisible();
        // "Net GSB" text also appears in the Gross GSB help-tip, so use first()
        await expect(page.locator('th').filter({ hasText: 'Net GSB' }).first()).toBeVisible();
    });

    test('at least one credited row is present', async ({ distributorPage: page }) => {
        await page.goto('/income/gsb-history');

        const rows = page.locator('table tbody tr');
        expect(await rows.count()).toBeGreaterThan(0);
        await expect(rows.first()).toContainText('Slab');
    });

    test('credited row shows correct deduction breakdown', async ({ distributorPage: page }) => {
        await page.goto('/income/gsb-history');

        const row = page.locator('table tbody tr').first();
        // Gross ₹ in column 5 (index 4)
        await expect(row.locator('td').nth(4)).toContainText('₹');
        // Admin charge is negative (column 6, index 5)
        await expect(row.locator('td').nth(5)).toContainText('-₹');
        // TDS is negative (column 7, index 6)
        await expect(row.locator('td').nth(6)).toContainText('-₹');
        // Net is positive ₹ (column 8, index 7)
        await expect(row.locator('td').nth(7)).toContainText('₹');
        // Status "Credited" (column 9, index 8)
        await expect(row.locator('td').nth(8)).toContainText('Credited');
    });

    test('month total row appears at the bottom', async ({ distributorPage: page }) => {
        await page.goto('/income/gsb-history');

        await expect(page.locator('tr').filter({ hasText: 'Month total' })).toBeVisible();
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

        await expect(page.getByText('Wallet Ledger', { exact: false })).toBeVisible();
        // Target the <td> cell specifically (not the tooltip span)
        await expect(page.locator('td').filter({ hasText: /^gsb_credit$/ }).first()).toBeVisible();
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

    test('wallet balance matches GSB history net total', async ({ distributorPage: page }) => {
        await page.goto('/income/wallet');

        // Get balance from the wallet balance card
        const balanceCard = page.locator('div.bg-white').filter({ hasText: 'Wallet Balance' }).first();
        const balanceText = (await balanceCard.locator('p.text-2xl').textContent())?.trim() ?? '';
        const balance = parseFloat(balanceText.replace(/[₹,]/g, ''));

        // Get month net total from GSB history (the last Month total row on the page)
        await page.goto('/income/gsb-history');
        const monthTotalRow = page.locator('tr').filter({ hasText: 'Month total' }).last();
        // The net total is the last td that contains a ₹ value (last td is empty)
        const netCellText = (await monthTotalRow.locator('td').filter({ hasText: /₹/ }).last().textContent())?.trim() ?? '';
        const netTotal = parseFloat(netCellText.replace(/[₹,]/g, ''));

        // Both should parse to valid numbers
        expect(Number.isFinite(balance)).toBe(true);
        expect(Number.isFinite(netTotal)).toBe(true);
        // And be within ₹1 of each other (no payouts yet, no MB credits in this dataset)
        expect(Math.abs(balance - netTotal)).toBeLessThanOrEqual(1);
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
