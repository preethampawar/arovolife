/**
 * Admin — horizontal overflow
 *
 * A page wider than the viewport is either a scrollbar the user did not ask
 * for or, because `body` carries `overflow-x-hidden`, columns they simply
 * cannot reach. Both have shipped here: a GRN line item whose product <select>
 * set a grid column's min-width (fixed with min-w-0), and 46 admin tables with
 * no `overflow-x-auto` wrapper, which clipped their right-hand columns on a
 * phone rather than letting them scroll inside the card.
 *
 * Measured at 390px on a fresh load per page — the admin shell sizes its
 * sidebar from the width it saw at load, so resizing without navigating
 * measures a stale layout.
 */
import { test, expect } from './fixtures.js';

const PAGES = [
    '/admin/inventory/warehouses',
    '/admin/inventory/suppliers',
    '/admin/inventory/stock',
    '/admin/inventory/grns/create',
    '/admin/compensation/engine-runs',
    '/admin/catalog/products',
    '/admin/kyc',
    '/admin/returns',
    '/admin',
];

test('no page-level horizontal overflow at 390', async ({ adminPage: page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    const bad = [];
    for (const url of PAGES) {
        await page.goto(url);
        const r = await page.evaluate(() => ({
            sw: document.documentElement.scrollWidth,
            vw: document.documentElement.clientWidth,
        }));
        console.log(`AUDIT ${r.sw <= r.vw ? 'OK      ' : 'OVERFLOW'} ${r.sw}/${r.vw}  ${url}`);
        if (r.sw > r.vw) { bad.push(`${url} (${r.sw}/${r.vw})`); }
    }
    expect(bad, `pages overflowing: ${bad.join(', ')}`).toEqual([]);
});
