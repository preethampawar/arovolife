/**
 * Profit on Sales — Browser Tests
 *
 * The four profit views, their exports and their explanatory copy.
 *
 * The permission matrix (admin-finance in, admin-compliance out) is asserted
 * in tests/Feature/Reports/ProfitReportTest.php instead: fixtures.js has no
 * scoped-role login helper, and adding one for this would be a bigger change
 * than the assertion is worth.
 *
 * Requires:
 *   - App running at APP_URL (default http://localhost:8084)
 *   - admin@arovolife.test / admin12345 (see fixtures.js)
 */

import { test, expect } from './fixtures.js';

test.describe.configure({ mode: 'serial' });

const VIEWS = [
  { slug: 'summary', heading: 'Profit summary' },
  { slug: 'by-product', heading: 'Profit by product' },
  { slug: 'by-category', heading: 'Profit by category' },
  { slug: 'register', heading: 'Profit register' },
];

test.describe('Profit on sales', () => {
  test('T1 — the index lists all four reports', async ({ adminPage }) => {
    await adminPage.goto('/admin/reports/profit');

    for (const { heading } of VIEWS) {
      await expect(adminPage.getByText(heading, { exact: true })).toBeVisible();
    }
  });

  test('T2 — the sidebar links to it', async ({ adminPage }) => {
    await adminPage.goto('/admin');
    await expect(adminPage.getByRole('link', { name: 'Profit on Sales' })).toBeVisible();
  });

  test('T3 — every view renders', async ({ adminPage }) => {
    for (const { slug, heading } of VIEWS) {
      const response = await adminPage.goto(`/admin/reports/profit/${slug}`);
      expect(response.status()).toBe(200);
      await expect(adminPage.getByRole('heading', { name: heading })).toBeVisible();
    }
  });

  test('T4 — the summary shows the trading-account lines that tie purchases to profit', async ({ adminPage }) => {
    await adminPage.goto('/admin/reports/profit/summary');

    for (const line of [
      'Opening stock (at cost)',
      'Add: Purchases (taxable, ex-GST)',
      'Add: Freight & other charges',
      'Less: Closing stock (at cost)',
      'Cost of goods sold',
      'Net sales',
      'Gross profit',
      'Contribution after commission',
    ]) {
      await expect(adminPage.getByText(line, { exact: true }).first()).toBeVisible();
    }
  });

  test('T5 — the product grid carries the margin columns and the cost basis', async ({ adminPage }) => {
    await adminPage.goto('/admin/reports/profit/by-product');

    for (const header of ['SKU', 'Net sales', 'COGS', 'Gross profit', 'Margin %', 'Markup %', 'Cost basis']) {
      await expect(adminPage.getByRole('columnheader', { name: header, exact: true })).toBeVisible();
    }
  });

  test('T6 — the explanatory panel says purchases are not cost of goods sold', async ({ adminPage }) => {
    await adminPage.goto('/admin/reports/profit/summary');

    await adminPage.getByText('How this report is calculated').first().click();
    await expect(adminPage.getByText('Purchases are not cost of goods sold.')).toBeVisible();
    await expect(adminPage.getByText('Contribution is not net profit.')).toBeVisible();
  });

  test('T7 — the date basis can be switched and survives the round trip', async ({ adminPage }) => {
    await adminPage.goto('/admin/reports/profit/by-product?basis=ordered');

    await expect(adminPage.locator('select[name="basis"]')).toHaveValue('ordered');
  });

  test('T8 — each view exports Excel and CSV', async ({ adminPage }) => {
    for (const { slug } of VIEWS) {
      await adminPage.goto(`/admin/reports/profit/${slug}`);

      const xlsx = adminPage.waitForEvent('download');
      await adminPage.getByRole('link', { name: '↓ Download Excel' }).click();
      expect((await xlsx).suggestedFilename()).toContain('profit');

      const csv = adminPage.waitForEvent('download');
      await adminPage.getByRole('link', { name: 'CSV', exact: true }).click();
      expect((await csv).suggestedFilename()).toMatch(/\.csv$/);
    }
  });

  test('T9 — a reversed date range renders an empty report rather than a 500', async ({ adminPage }) => {
    const response = await adminPage.goto(
      '/admin/reports/profit/by-product?date_from=2030-01-01&date_to=2020-01-01',
    );

    expect(response.status()).toBe(200);
    await expect(adminPage.getByText('No sales in this period.')).toBeVisible();
  });

  test('T10 — a junk basis falls back to the default instead of erroring', async ({ adminPage }) => {
    const response = await adminPage.goto('/admin/reports/profit/summary?basis=nonsense');

    expect(response.status()).toBe(200);
    await expect(adminPage.locator('select[name="basis"]')).toHaveValue('shipped');
  });
});

test.describe('Landing price is system-owned', () => {
  test('T11 — a product with purchase history shows landing price read-only with its history', async ({ adminPage }) => {
    await adminPage.goto('/admin/catalog/products');

    const firstEdit = adminPage.getByRole('link', { name: /edit/i }).first();

    if ((await firstEdit.count()) === 0) {
      test.skip(true, 'No products in the dev database to inspect.');
    }

    await firstEdit.click();

    const input = adminPage.locator('input[name="landing_price"]');
    await expect(input).toBeVisible();

    // Either state is valid depending on whether this product has a posted
    // GRN; what must hold is that a read-only field always offers the history.
    if (await input.getAttribute('readonly') !== null) {
      await expect(adminPage.getByRole('link', { name: 'View history' })).toBeVisible();
    } else {
      await expect(adminPage.getByText('Starting value — the system takes this over after the first GRN.')).toBeVisible();
    }
  });
});
