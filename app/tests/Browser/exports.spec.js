/**
 * Report exports (XLSX + CSV) - Browser Tests
 *
 * Covers the ReportExport facade rollout (slice S10 of
 * docs/plans/csv-to-xlsx-exports-2026-09-12.md): every human-facing report
 * download now offers both `.xlsx` (default) and `.csv` (?format=csv, or the
 * legacy ?export=csv on the inventory reports), off the same URL, with the
 * same permission gate as before the refactor.
 *
 * Requires:
 *   - the e2e stack on http://localhost:8085, world gsb-basic (fixtures.js)
 *   - T12 (NEFT export) runs on gsb-basic with a real, finance-approved
 *     weekly payout batch (e2e:engines --weekly-batch); T12b the same for the
 *     monthly batch (--monthly-batch)
 */

import { readFileSync } from 'node:fs';
import { test, expect, loginAsStaff, submitAndConfirm } from './fixtures.js';

test.describe.configure({ mode: 'serial' });

// ---------------------------------------------------------------------------
// T1-T3, T16, T17 - Inventory reports (P1)
// ---------------------------------------------------------------------------

test.describe('Inventory reports export', () => {
    test('T1: stock-on-hand -> Export Excel downloads .xlsx', async ({ adminPage: page }) => {
        await page.goto('/admin/inventory/reports/stock-on-hand');
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('link', { name: 'Export Excel' }).click(),
        ]);
        expect(download.suggestedFilename()).toBe('inventory-stock-on-hand.xlsx');
    });

    test('T2: stock-on-hand shows both Export Excel and CSV buttons', async ({ adminPage: page }) => {
        await page.goto('/admin/inventory/reports/stock-on-hand');
        await expect(page.getByRole('link', { name: 'Export Excel' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'CSV', exact: true })).toBeVisible();
    });

    test('T3: valuation -> Export Excel downloads inventory-valuation.xlsx', async ({ adminPage: page }) => {
        await page.goto('/admin/inventory/reports/valuation');
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('link', { name: 'Export Excel' }).click(),
        ]);
        expect(download.suggestedFilename()).toBe('inventory-valuation.xlsx');
    });

    test('T16: legacy ?export=csv still downloads CSV', async ({ adminPage: page }) => {
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            // The response is an attachment, so the navigation never commits
            // and page.goto() rejects with "Download is starting" even though
            // the download itself arrives. Swallow exactly that rejection —
            // any other navigation error still fails the test, and so does a
            // download that never turns up.
            page.goto('/admin/inventory/reports/stock-on-hand?export=csv').catch((err) => {
                if (!/Download is starting/.test(err.message)) throw err;
            }),
        ]);
        expect(download.suggestedFilename()).toBe('inventory-stock-on-hand.csv');
    });

    test('T17: stock-on-hand -> CSV button downloads .csv', async ({ adminPage: page }) => {
        await page.goto('/admin/inventory/reports/stock-on-hand');
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('link', { name: 'CSV', exact: true }).click(),
        ]);
        expect(download.suggestedFilename()).toBe('inventory-stock-on-hand.csv');
    });
});

// ---------------------------------------------------------------------------
// T4, T18, T20, T21 - GSB calculation (P6)
// ---------------------------------------------------------------------------

test.describe('Compensation: GSB calculation export', () => {
    test('T4: Download Excel matches gsb-calculation-YYYY-MM-DD.xlsx', async ({ adminPage: page }) => {
        await page.goto('/admin/compensation/gsb-calculation');
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('link', { name: 'Download Excel' }).click(),
        ]);
        expect(download.suggestedFilename()).toMatch(/^gsb-calculation-\d{4}-\d{2}-\d{2}\.xlsx$/);
    });

    test('T18: CSV button downloads .csv', async ({ adminPage: page }) => {
        await page.goto('/admin/compensation/gsb-calculation');
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('link', { name: 'CSV', exact: true }).click(),
        ]);
        expect(download.suggestedFilename()).toMatch(/\.csv$/);
    });

    test('T20: bare export URL (no format param) downloads .xlsx, not .csv', async ({ adminPage: page }) => {
        // Unlike the inventory reports (whose route serves both the HTML
        // report page and the export, switching on the presence of
        // ?export=/?format=), the GSB calculation export lives at its own
        // dedicated URL (.../gsb-calculation/export) which always streams a
        // file. Hitting it with no query params at all directly tests D2's
        // default-to-XLSX behaviour.
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            // Same attachment-navigation rejection as T16.
            page.goto('/admin/compensation/gsb-calculation/export').catch((err) => {
                if (!/Download is starting/.test(err.message)) throw err;
            }),
        ]);
        expect(download.suggestedFilename()).toMatch(/\.xlsx$/);
    });

    test('T21: CSV button preserves an active filter query param', async ({ adminPage: page }) => {
        // Apply a status filter, then check the CSV anchor's href still
        // carries it, not only the export-format param.
        await page.goto('/admin/compensation/gsb-calculation?status=credited');
        const href = await page.getByRole('link', { name: 'CSV', exact: true }).getAttribute('href');
        expect(href).toContain('status=credited');
        expect(href).toContain('format=csv');
    });
});

// ---------------------------------------------------------------------------
// T5 - Rank bonus calculation (P6)
// ---------------------------------------------------------------------------

test.describe('Compensation: rank bonus calculation export', () => {
    test('T5: Download Excel downloads an .xlsx file', async ({ adminPage: page }) => {
        await page.goto('/admin/compensation/rb-calculation');
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('link', { name: 'Download Excel' }).click(),
        ]);
        expect(download.suggestedFilename()).toMatch(/\.xlsx$/);
    });
});

// ---------------------------------------------------------------------------
// T6 - BV ledger export (P3) - converted (S6b: lazy/cursor iteration)
// ---------------------------------------------------------------------------

test.describe('Commerce: BV ledger export', () => {
    test('T6: Export Excel downloads .xlsx', async ({ adminPage: page }) => {
        await page.goto('/admin/commerce/bv-ledger');
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('link', { name: 'Export Excel' }).click(),
        ]);
        expect(download.suggestedFilename()).toMatch(/\.xlsx$/);
    });

    test('T6: CSV button downloads .csv', async ({ adminPage: page }) => {
        await page.goto('/admin/commerce/bv-ledger');
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('link', { name: 'CSV', exact: true }).click(),
        ]);
        expect(download.suggestedFilename()).toMatch(/\.csv$/);
    });
});

// ---------------------------------------------------------------------------
// T7 - Distributors / DSR register (P2)
// ---------------------------------------------------------------------------

test.describe('Admin distributors: DSR register export', () => {
    test('T7: Export Excel (DSR Register) downloads register-of-direct-sellers-*.xlsx', async ({ adminPage: page }) => {
        await page.goto('/admin/distributors');
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('link', { name: /Export Excel \(DSR Register\)/ }).click(),
        ]);
        expect(download.suggestedFilename()).toMatch(/^register-of-direct-sellers-.*\.xlsx$/);
    });
});

// ---------------------------------------------------------------------------
// T8 - Grievance report (P5)
// ---------------------------------------------------------------------------

test.describe('Grievance report export', () => {
    test('T8: Export 12 months (Excel) downloads .xlsx', async ({ adminPage: page }) => {
        await page.goto('/admin/grievances/report');
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('link', { name: 'Export 12 months (Excel)' }).click(),
        ]);
        expect(download.suggestedFilename()).toMatch(/\.xlsx$/);
    });
});

// ---------------------------------------------------------------------------
// T9, T19 - Income: GSB history (P7)
// ---------------------------------------------------------------------------

test.describe('Income: GSB history export', () => {
    test('T9: Excel button downloads .xlsx', async ({ distributorPage: page }) => {
        await page.goto('/income/gsb-history');
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('link', { name: /Excel/ }).click(),
        ]);
        expect(download.suggestedFilename()).toMatch(/\.xlsx$/);
    });

    test('T19: CSV button downloads .csv', async ({ distributorPage: page }) => {
        await page.goto('/income/gsb-history');
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('link', { name: 'CSV', exact: true }).click(),
        ]);
        expect(download.suggestedFilename()).toMatch(/\.csv$/);
    });
});

// ---------------------------------------------------------------------------
// T10 - Income: wallet (P8)
// ---------------------------------------------------------------------------

test.describe('Income: wallet export', () => {
    test('T10: Excel button downloads .xlsx', async ({ distributorPage: page }) => {
        await page.goto('/income/wallet');
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('link', { name: /Excel/ }).click(),
        ]);
        expect(download.suggestedFilename()).toMatch(/\.xlsx$/);
    });
});

// ---------------------------------------------------------------------------
// T11 - Dashboard: My Team roster (P9)
// ---------------------------------------------------------------------------

test.describe('Dashboard: My Team roster export', () => {
    test('T11: Download Excel (from the team-roster modal) downloads .xlsx', async ({ distributorPage: page }) => {
        await page.goto('/dashboard');
        // The hook appears twice on the dashboard — once on the ID-card panel
        // and once on the My Team card — and both open the same modal.
        const totalCard = page.locator('[data-team-roster="total"]').first();
        // The root of gsb-basic has two downline partners, so the card renders.
        await expect(totalCard).toBeVisible();
        await totalCard.click();
        await expect(page.locator('#team-roster-modal')).toBeVisible();
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.locator('#team-roster-download').click(),
        ]);
        expect(download.suggestedFilename()).toMatch(/\.xlsx$/);
    });
});

// ---------------------------------------------------------------------------
// T12 - NEFT export (P10) - must STILL be CSV (negative test on the exemption)
// ---------------------------------------------------------------------------

test.describe('Payout batch: NEFT export stays CSV', () => {
    // gsb-basic paid through the real weekly batch: root's slab-1 GSB earned
    // on 3 June lands in the batch of Tuesday 16 June (earning week 3–9 June),
    // signed off by finance. Net = ₹2,000 gross − ₹200 repurchase (held at
    // credit) − ₹60 admin charge (3%) − ₹87 TDS (5% of ₹1,740) = ₹1,653.00.
    test.use({
        world: { name: 'gsb-basic', asOf: '2026-06-17 06:00:00', weeklyBatch: '2026-06-16', approveAs: 'finance@e2e.test' },
    });

    test('T12: NEFT bank-file download is still .csv and pays root ₹1,653.00', async ({ page, builtWorld }) => {
        await loginAsStaff(page, builtWorld, 'finance');
        await page.goto('/admin/compensation/weekly-payouts');
        const batchLink = page.locator('tbody tr').filter({ hasText: /16 Jun 2026/ }).locator('a[href*="/admin/compensation/weekly-payouts/"]').first();
        await expect(batchLink).toBeVisible();
        await batchLink.click();

        const [download] = await Promise.all([
            page.waitForEvent('download'),
            submitAndConfirm(page, page.getByRole('button', { name: /Download bank file \(NEFT\)/ })),
        ]);
        expect(download.suggestedFilename()).toMatch(/\.csv$/);

        const csv = readFileSync(await download.path(), 'utf8');
        const rows = csv.trim().split('\n');
        expect(rows).toHaveLength(2);
        const root = builtWorld.partners.root;
        expect(rows[1]).toContain(root.adn);
        expect(rows[1]).toContain('500000000001');
        expect(rows[1]).toContain('SBIN0000001');
        expect(rows[1]).toContain('1653.00');
    });
});

test.describe('Payout batch: monthly NEFT export', () => {
    // gsb-basic paid through the 8th's real payout close for June. Company BV
    // 33,600 (3,200 + 15,200 + 15,200) × 6% = ₹2,016 GBB pool; root is the only
    // earner (12 AGP from one slab-1 GSB cut-off → ₹168 an AGP, under the ₹240
    // cap) so takes ₹2,016. Fortune: position 1, level 0 pays the ₹30 minimum.
    // Gross ₹2,046 − ₹204.60 repurchase (10%, held at credit) − ₹61.38 admin
    // charge (3% of gross) − ₹89.00 TDS (5% of ₹1,780.02) = ₹1,691.02.
    test.use({
        world: { name: 'gsb-basic', asOf: '2026-07-08 06:00:00', monthlyBatch: '2026-06', approveAs: 'finance@e2e.test' },
    });

    test('T12b: monthly NEFT bank file is .csv and pays root ₹1,691.02', async ({ page, builtWorld }) => {
        await loginAsStaff(page, builtWorld, 'finance');
        await page.goto('/admin/compensation/monthly-payouts');
        const batchLink = page.locator('tbody tr').filter({ hasText: /01 Jul 2026/ }).locator('a[href*="/admin/compensation/monthly-payouts/"]').first();
        await expect(batchLink).toBeVisible();
        await batchLink.click();

        const [download] = await Promise.all([
            page.waitForEvent('download'),
            submitAndConfirm(page, page.getByRole('button', { name: /Download bank file \(NEFT\)/ })),
        ]);
        expect(download.suggestedFilename()).toMatch(/\.csv$/);

        const rows = readFileSync(await download.path(), 'utf8').trim().split('\n');
        expect(rows).toHaveLength(2);
        expect(rows[1]).toContain(builtWorld.partners.root.adn);
        expect(rows[1]).toContain('SBIN0000001');
        expect(rows[1]).toContain('1691.02');
    });
});

// ---------------------------------------------------------------------------
// T13, T14 - Permission negatives: distributor hitting admin export URLs
// ---------------------------------------------------------------------------

test.describe('Permission negatives', () => {
    test('T13: distributor hitting an admin inventory export URL is refused', async ({ distributorPage: page }) => {
        const response = await page.goto('/admin/inventory/reports/stock-on-hand?export=xlsx');
        expect([403, 302]).toContain(response.status());
        if (response.status() === 302) {
            // Redirects (e.g. to /login) must not land the distributor on the
            // report itself.
            await page.waitForLoadState('domcontentloaded');
            expect(page.url()).not.toContain('/admin/inventory/reports/stock-on-hand');
        }
    });

    test('T14: distributor hitting the GSB calculation export URL is refused', async ({ distributorPage: page }) => {
        const response = await page.goto('/admin/compensation/gsb-calculation/export');
        expect([403, 302]).toContain(response.status());
        if (response.status() === 302) {
            await page.waitForLoadState('domcontentloaded');
            expect(page.url()).not.toContain('/admin/compensation/gsb-calculation/export');
        }
    });
});

// ---------------------------------------------------------------------------
// T15 - Zero-row report still downloads (no 500)
// ---------------------------------------------------------------------------

test.describe('Edge case: zero-row export', () => {
    test('T15: a filter that yields zero rows still downloads without a 500', async ({ adminPage: page }) => {
        // An implausible warehouse-code filter on stock-on-hand should return
        // no matching rows while the export endpoint itself still streams a
        // valid file rather than erroring.
        await page.goto('/admin/inventory/reports/stock-on-hand?warehouse_code=ZZZ-DOES-NOT-EXIST');
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('link', { name: 'Export Excel' }).click(),
        ]);
        expect(download.suggestedFilename()).toMatch(/\.xlsx$/);
        const failure = await download.failure();
        expect(failure).toBeNull();
    });
});
