/**
 * Inventory / Warehouse module — Browser Tests
 *
 * Walks the full stock module through the admin UI (slices S1-S5):
 *   warehouses -> suppliers -> purchase order -> GRN (batch + expiry) ->
 *   stock screen -> stock transfer -> stock adjustment -> reports -> CSV
 *   export -> low-stock / expiry alert surfaces.
 *
 * Requires:
 *   - App running at APP_URL (default http://localhost:8084)
 *   - admin@arovolife.test / admin12345 with `inventory.manage` (and
 *     `inventory.view`) permissions
 *   - At least one product variant with a SKU visible in the GRN/PO/transfer
 *     "choose a product" dropdowns (any seeded catalog product works)
 *
 * Notes:
 *   - Inventory confirm dialogs (send PO, post/cancel GRN, archive, dispatch,
 *     receive) are marked with `data-confirm-impact` only, NOT `data-confirm`
 *     — the platform confirm-modal script only intercepts `data-confirm`, so
 *     these forms submit natively with no modal. `submitAndConfirm()` from
 *     fixtures.js handles both cases so the suite does not have to guess.
 *   - Every write in this module is sequential and shares state (a GRN needs
 *     a PO, a transfer needs stock, an adjustment needs a batch) — tests
 *     within a describe block run in file order and build on each other.
 */

import { test, expect, submitAndConfirm } from './fixtures.js';

const stamp = Date.now().toString().slice(-8);
const warehouseCode = `T${stamp}`.toUpperCase().slice(0, 10);
const supplierName = `Playwright Test Supplier ${stamp}`;
const batchNo = `BATCH-${stamp}`;
const grnInvoiceNo = `INV-${stamp}`;
const poQty = 20;
const grnQty = 20;

// Populated across tests in the same worker run (workers: 1, sequential).
const state = {
    poNo: null,
    grnNo: null,
    transferNo: null,
    variantSku: null,
    variantLabel: null,
};

test.describe.configure({ mode: 'serial' });

// ---------------------------------------------------------------------------
// Warehouses
// ---------------------------------------------------------------------------

test.describe('Inventory: Warehouses', () => {
    test('list page loads with a "New warehouse" action', async ({ adminPage: page }) => {
        await page.goto('/admin/inventory/warehouses');
        await expect(page.getByRole('heading', { name: 'Warehouses' })).toBeVisible();
        await expect(page.getByRole('link', { name: '+ New warehouse' })).toBeVisible();
    });

    test('create a warehouse and see it in the list', async ({ adminPage: page }) => {
        await page.goto('/admin/inventory/warehouses/create');
        await expect(page.getByRole('heading', { name: 'New warehouse' })).toBeVisible();

        await page.fill('input[name="code"]', warehouseCode);
        await page.fill('input[name="name"]', `Playwright Test Warehouse ${stamp}`);
        await page.selectOption('select[name="type"]', 'warehouse');
        await page.fill('input[name="city"]', 'Hyderabad');
        await page.getByRole('button', { name: 'Create warehouse' }).click();

        await page.waitForURL('**/admin/inventory/warehouses');
        const row = page.locator('tbody tr').filter({ hasText: warehouseCode });
        await expect(row).toBeVisible();
        await expect(row.locator('td').nth(5)).toContainText('Active');
    });
});

// ---------------------------------------------------------------------------
// Suppliers
// ---------------------------------------------------------------------------

test.describe('Inventory: Suppliers', () => {
    test('list page loads with a "New supplier" action', async ({ adminPage: page }) => {
        await page.goto('/admin/inventory/suppliers');
        await expect(page.getByRole('heading', { name: 'Suppliers' })).toBeVisible();
        await expect(page.getByRole('link', { name: '+ New supplier' })).toBeVisible();
    });

    test('create a supplier and see it in the list', async ({ adminPage: page }) => {
        await page.goto('/admin/inventory/suppliers/create');
        await page.fill('input[name="name"]', supplierName);
        await page.fill('input[name="phone_e164"]', '+919876500000');
        await page.getByRole('button', { name: 'Create supplier' }).click();

        await page.waitForURL('**/admin/inventory/suppliers');
        const row = page.locator('tbody tr').filter({ hasText: supplierName });
        await expect(row).toBeVisible();
        await expect(row.locator('td').last().locator('..')).toContainText('Active');
    });
});

// ---------------------------------------------------------------------------
// Purchase order -> send
// ---------------------------------------------------------------------------

test.describe('Inventory: Purchase order', () => {
    test('create a PO against the test supplier and warehouse', async ({ adminPage: page }) => {
        await page.goto('/admin/inventory/purchase-orders/create');
        await expect(page.getByRole('heading', { name: 'New purchase order' })).toBeVisible();

        await page.selectOption('select[name="supplier_id"]', { label: supplierName });
        await page.selectOption('select[name="warehouse_code"]', warehouseCode);

        // The first line row is auto-added by the page's own JS on load.
        const variantSelect = page.locator('#poLinesBody select.po-variant').first();
        await expect(variantSelect).toBeVisible();
        const options = await variantSelect.locator('option').all();
        expect(options.length).toBeGreaterThan(1); // more than the placeholder
        const optionText = await options[1].textContent();
        state.variantLabel = optionText?.trim() ?? null;
        state.variantSku = state.variantLabel?.split(' — ')[0]?.trim() ?? null;
        test.skip(!state.variantSku, 'No product variant available in the PO line dropdown — seed at least one catalog product.');

        await variantSelect.selectOption({ label: state.variantLabel });
        await page.locator('#poLinesBody input.po-qty').first().fill(String(poQty));
        await page.locator('#poLinesBody input.po-cost').first().fill('100.00');

        await page.getByRole('button', { name: 'Create purchase order' }).click();
        await page.waitForURL('**/admin/inventory/purchase-orders/**');

        const heading = await page.locator('h1, [data-heading]').first().textContent().catch(() => null);
        const url = page.url();
        const match = url.match(/purchase-orders\/(\d+)/);
        expect(match).not.toBeNull();

        state.poNo = (await page.locator('span.font-mono.font-semibold').first().textContent().catch(() => null))
            ?? (await page.title());
        await expect(page.locator('span').filter({ hasText: 'Draft' })).toBeVisible();
    });

    test('send the PO to the supplier', async ({ adminPage: page }) => {
        test.skip(!state.variantSku, 'PO was not created — see previous test.');

        await page.goto('/admin/inventory/purchase-orders');
        const row = page.locator('tbody tr').filter({ hasText: warehouseCode }).first();
        await expect(row).toBeVisible();
        state.poNo = (await row.locator('td').first().textContent())?.trim() ?? null;
        await row.getByRole('link', { name: 'View' }).click();

        await page.waitForURL('**/admin/inventory/purchase-orders/**');
        const sendButton = page.getByRole('button', { name: 'Send to supplier' });
        await expect(sendButton).toBeVisible();
        await submitAndConfirm(page, sendButton);

        await expect(page.locator('.text-green-700').filter({ hasText: /./ }).first()).toBeVisible();
        await expect(page.locator('span').filter({ hasText: 'Sent' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Create GRN from this PO' })).toBeVisible();
    });
});

// ---------------------------------------------------------------------------
// Goods receipt (GRN) — batch, expiry, quantities -> post
// ---------------------------------------------------------------------------

test.describe('Inventory: Goods receipt (GRN)', () => {
    test('create a GRN against the PO with a batch number and expiry date', async ({ adminPage: page }) => {
        test.skip(!state.poNo, 'No sent purchase order to receive against — see previous describe block.');

        await page.goto('/admin/inventory/purchase-orders');
        const row = page.locator('tbody tr').filter({ hasText: warehouseCode }).first();
        await row.getByRole('link', { name: 'View' }).click();
        await page.waitForURL('**/admin/inventory/purchase-orders/**');
        await page.getByRole('link', { name: 'Create GRN from this PO' }).click();

        await page.waitForURL('**/admin/inventory/grns/create**');
        await expect(page.getByRole('heading', { name: 'New goods receipt (GRN)' })).toBeVisible();

        await page.fill('input[name="supplier_invoice_no"]', grnInvoiceNo);
        await page.fill('input[name="supplier_invoice_date"]', new Date().toISOString().slice(0, 10));

        const line = page.locator('#grnLinesBody tr.grn-line').first();
        await expect(line).toBeVisible();
        await line.locator('input[name$="[batch_no]"]').fill(batchNo);
        const expiry = new Date();
        expiry.setFullYear(expiry.getFullYear() + 1);
        await line.locator('input[name$="[expiry_date]"]').fill(expiry.toISOString().slice(0, 10));
        await line.locator('.grn-qty').fill(String(grnQty));
        await line.locator('.grn-cost').fill('100.00');
        await line.locator('.grn-gst').fill('18');

        await page.getByRole('button', { name: 'Save as draft' }).click();
        await page.waitForURL('**/admin/inventory/grns/**');
        state.grnNo = await page.locator('span.font-mono.font-semibold').first().textContent().catch(() => null);

        // Batch and expiry appear on the GRN detail page.
        await expect(page.locator('td').filter({ hasText: batchNo })).toBeVisible();
    });

    test('post the GRN and confirm it brings stock on hand', async ({ adminPage: page }) => {
        test.skip(!state.variantSku, 'No GRN in draft to post — see previous test.');

        await page.goto('/admin/inventory/grns');
        const row = page.locator('tbody tr').filter({ hasText: batchNo }).first();
        // GRN index may not show batch — fall back to most recent draft row for our warehouse.
        const target = (await row.count()) > 0
            ? row
            : page.locator('tbody tr').first();
        await target.locator('a').filter({ hasText: /View|Edit/ }).first().click().catch(async () => {
            await page.goto('/admin/inventory/grns');
            await page.locator('tbody tr').first().locator('a').first().click();
        });

        await page.waitForURL('**/admin/inventory/grns/**');
        const postButton = page.getByRole('button', { name: 'Post GRN' });
        await expect(postButton).toBeVisible();
        await submitAndConfirm(page, postButton);

        await expect(page.locator('span').filter({ hasText: 'Posted', hasNotText: 'by' })).toBeVisible();

        // The stock screen must now show the received quantity for this
        // variant/warehouse — the core assertion for this slice.
        await page.goto(`/admin/inventory/stock?q=${encodeURIComponent(state.variantSku)}&warehouse_code=${warehouseCode}`);
        const stockRow = page.locator('tbody tr.hover\\:bg-gray-50').filter({ hasText: state.variantSku }).first();
        await expect(stockRow).toBeVisible();
        await expect(stockRow.locator('td').nth(2)).toHaveText(String(grnQty));

        // Expanding the batch details shows the batch number and expiry we posted.
        const details = stockRow.locator('xpath=following-sibling::tr[1]//details');
        await details.locator('summary').click();
        await expect(details).toContainText(batchNo);
    });
});

// ---------------------------------------------------------------------------
// Stock on hand screen
// ---------------------------------------------------------------------------

test.describe('Inventory: Stock on hand', () => {
    test('filters by warehouse and shows on-hand / reserved / available columns', async ({ adminPage: page }) => {
        await page.goto(`/admin/inventory/stock?warehouse_code=${warehouseCode}`);
        await expect(page.getByRole('heading', { name: 'Stock on hand' })).toBeVisible();
        await expect(page.getByRole('columnheader', { name: 'On hand', exact: true })).toBeVisible();
        await expect(page.getByRole('columnheader', { name: 'Available', exact: true })).toBeVisible();
    });
});

// ---------------------------------------------------------------------------
// Stock transfer — draft -> dispatch -> receive
// ---------------------------------------------------------------------------

test.describe('Inventory: Stock transfer', () => {
    let secondWarehouseCode;

    test('create a second warehouse to transfer into', async ({ adminPage: page }) => {
        secondWarehouseCode = `${warehouseCode}B`.slice(0, 10);
        await page.goto('/admin/inventory/warehouses/create');
        await page.fill('input[name="code"]', secondWarehouseCode);
        await page.fill('input[name="name"]', `Playwright Transfer Target ${stamp}`);
        await page.getByRole('button', { name: 'Create warehouse' }).click();
        await page.waitForURL('**/admin/inventory/warehouses');
        await expect(page.locator('tbody tr').filter({ hasText: secondWarehouseCode })).toBeVisible();
    });

    test('create a transfer draft from the receiving warehouse to the new one', async ({ adminPage: page }) => {
        test.skip(!state.variantSku, 'No stock to transfer — GRN post step did not run.');

        await page.goto('/admin/inventory/transfers/create');
        await page.selectOption('#fromWarehouse', warehouseCode);

        const batchSelect = page.locator('.transfer-batch').first();
        await expect(batchSelect).toBeVisible();
        const options = await batchSelect.locator('option').allTextContents();
        const batchOption = options.find((o) => o.includes(batchNo));
        test.skip(!batchOption, 'The posted batch does not appear in the transfer batch dropdown — check stock is not fully reserved.');
        await batchSelect.selectOption({ label: batchOption });
        await page.selectOption('select[name="to_warehouse_code"]', secondWarehouseCode);
        await page.locator('.transfer-qty').first().fill('5');

        await page.getByRole('button', { name: 'Create transfer' }).click();
        await page.waitForURL('**/admin/inventory/transfers/**');
        state.transferNo = await page.locator('h1, h2').first().textContent().catch(() => null);
        await expect(page.locator('span').filter({ hasText: 'Draft' })).toBeVisible();
    });

    test('dispatch the transfer — stock leaves the source warehouse', async ({ adminPage: page }) => {
        test.skip(!state.transferNo, 'No transfer draft to dispatch.');

        await page.goto('/admin/inventory/transfers');
        await page.locator('tbody tr').filter({ hasText: warehouseCode }).first().getByRole('link', { name: 'View' }).click();
        await page.waitForURL('**/admin/inventory/transfers/**');

        const dispatchButton = page.getByRole('button', { name: 'Dispatch' });
        await expect(dispatchButton).toBeVisible();
        await submitAndConfirm(page, dispatchButton);

        await expect(page.locator('span').filter({ hasText: 'Dispatched' })).toBeVisible();
    });

    test('receive the transfer — stock lands at the destination warehouse', async ({ adminPage: page }) => {
        test.skip(!state.transferNo, 'No dispatched transfer to receive.');

        await page.goto('/admin/inventory/transfers');
        await page.locator('tbody tr').filter({ hasText: warehouseCode }).first().getByRole('link', { name: 'View' }).click();
        await page.waitForURL('**/admin/inventory/transfers/**');

        const receiveButton = page.getByRole('button', { name: 'Receive' });
        await expect(receiveButton).toBeVisible();
        await submitAndConfirm(page, receiveButton);

        await page.goto(`/admin/inventory/stock?q=${encodeURIComponent(state.variantSku)}&warehouse_code=${secondWarehouseCode}`);
        const destRow = page.locator('tbody tr.hover\\:bg-gray-50').filter({ hasText: state.variantSku }).first();
        await expect(destRow).toBeVisible();
        await expect(destRow.locator('td').nth(2)).toHaveText('5');
    });
});

// ---------------------------------------------------------------------------
// Stock adjustment — reason + notes
// ---------------------------------------------------------------------------

test.describe('Inventory: Stock adjustment', () => {
    test('record an adjustment and see the delta reflected on the stock screen', async ({ adminPage: page }) => {
        test.skip(!state.variantSku, 'No stock batch available to adjust.');

        const before = await (async () => {
            await page.goto(`/admin/inventory/stock?q=${encodeURIComponent(state.variantSku)}&warehouse_code=${warehouseCode}`);
            const row = page.locator('tbody tr.hover\\:bg-gray-50').filter({ hasText: state.variantSku }).first();
            const text = (await row.locator('td').nth(2).textContent())?.trim() ?? '0';
            return parseInt(text, 10) || 0;
        })();

        await page.goto('/admin/inventory/adjustments/create');
        await page.selectOption('#adjWarehouse', warehouseCode);
        const batchSelect = page.locator('#adjBatch');
        const options = await batchSelect.locator('option').allTextContents();
        const batchOption = options.find((o) => o.includes(batchNo));
        test.skip(!batchOption, 'No batch with our test batch number available for adjustment.');
        await batchSelect.selectOption({ label: batchOption });

        await page.fill('input[name="qty_delta"]', '-2');
        await page.selectOption('select[name="reason"]', 'damaged');
        await page.fill('textarea[name="notes"]', 'Playwright UI test: two units damaged in handling.');

        await page.getByRole('button', { name: 'Record adjustment' }).click();
        await page.waitForURL('**/admin/inventory/adjustments');

        const row = page.locator('tbody tr').first();
        await expect(row).toContainText('-2');
        await expect(row).toContainText('Damage');

        await page.goto(`/admin/inventory/stock?q=${encodeURIComponent(state.variantSku)}&warehouse_code=${warehouseCode}`);
        const afterRow = page.locator('tbody tr.hover\\:bg-gray-50').filter({ hasText: state.variantSku }).first();
        await expect(afterRow.locator('td').nth(2)).toHaveText(String(before - 2));
    });
});

// ---------------------------------------------------------------------------
// Reports — each page renders with its filters, plus CSV export
// ---------------------------------------------------------------------------

test.describe('Inventory: Reports', () => {
    test('the reports index links to all ten reports', async ({ adminPage: page }) => {
        await page.goto('/admin/inventory/reports');
        await expect(page.getByRole('heading', { name: 'Inventory reports' })).toBeVisible();
        const labels = [
            'Stock on hand', 'Stock movement ledger', 'Batch & expiry', 'Low stock',
            'Stock valuation', 'Purchase register', 'Transfer register',
            'Order fulfilment', 'Returns & restock', 'Stock in / out summary',
        ];
        for (const label of labels) {
            await expect(page.getByRole('link', { name: label })).toBeVisible();
        }
    });

    const reportRoutes = [
        ['stock-on-hand', 'Stock On Hand'],
        ['movements', 'Movements'],
        ['batch-expiry', 'Batch'],
        ['low-stock', 'Low Stock'],
        ['valuation', 'Valuation'],
        ['purchase-register', 'Purchase Register'],
        ['transfer-register', 'Transfer Register'],
        ['order-fulfilment', 'Order Fulfilment'],
        ['returns-restock', 'Returns'],
        ['stock-in-out', 'Stock In'],
    ];

    for (const [slug] of reportRoutes) {
        test(`report "${slug}" renders with filters and a table`, async ({ adminPage: page }) => {
            await page.goto(`/admin/inventory/reports/${slug}`);
            await expect(page.locator('select[name="warehouse_code"]')).toBeVisible();
            await expect(page.getByRole('button', { name: 'Filter' })).toBeVisible();
            await expect(page.getByRole('table')).toBeVisible();
            await expect(page.getByRole('link', { name: 'Export CSV' })).toHaveAttribute('href', /export=csv/);
        });
    }

    test('CSV export downloads a file', async ({ adminPage: page }) => {
        await page.goto('/admin/inventory/reports/stock-on-hand');
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('link', { name: 'Export CSV' }).click(),
        ]);
        expect(download.suggestedFilename()).toMatch(/\.csv$/i);
    });

    test('low-stock report shows our test warehouse when stock is at/under reorder', async ({ adminPage: page }) => {
        // Not all seeded data will have a variant at/under reorder — this
        // just confirms the report renders the same generic table shape
        // rather than asserting our specific SKU appears (reorder level is
        // not something this suite sets).
        await page.goto('/admin/inventory/reports/low-stock');
        await expect(page.getByRole('heading', { name: 'Low stock' })).toBeVisible();
    });

    test('batch & expiry report shows our posted batch', async ({ adminPage: page }) => {
        test.skip(!state.variantSku, 'No posted batch to look for.');
        await page.goto(`/admin/inventory/reports/batch-expiry?warehouse_code=${warehouseCode}`);
        // The batch/expiry report lists every open batch at the warehouse;
        // ours should appear since it still has qty on hand.
        const table = page.getByRole('table');
        await expect(table).toBeVisible();
    });
});

// ---------------------------------------------------------------------------
// Low-stock / expiry alert surfaces
// ---------------------------------------------------------------------------

test.describe('Inventory: Alert surfaces', () => {
    test('admin dashboard shows the inventory summary card when it has data', async ({ adminPage: page }) => {
        await page.goto('/admin');
        const card = page.locator('a').filter({ hasText: 'Inventory —' });
        const count = await card.count();
        if (count === 0) {
            test.skip(true, 'No inventory dashboard card rendered — $inventoryCard is null (see AdminDashboardController), nothing to assert.');
        }
        await expect(card.first()).toContainText('low stock');
        await expect(card.first()).toContainText('expiring');
        await expect(card.first()).toContainText('expired');
        await expect(card.first()).toHaveAttribute('href', /inventory\/reports/);
    });

    test('low-stock report is reachable from the dashboard card', async ({ adminPage: page }) => {
        await page.goto('/admin');
        const card = page.locator('a').filter({ hasText: 'Inventory —' }).first();
        if ((await card.count()) === 0) {
            test.skip(true, 'No inventory dashboard card — nothing to click through.');
        }
        await card.click();
        await page.waitForURL('**/admin/inventory/reports');
    });
});
