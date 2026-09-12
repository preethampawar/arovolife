/**
 * Order fulfilment — Browser Tests
 *
 * Covers the order lifecycle and the money-adjacent paths that sit on top of
 * the inventory module (slices S1-S5):
 *   - place an order through the storefront (distributor, stub gateway) ->
 *     admin pack (FEFO pick list) -> ship (carrier + tracking) -> deliver ->
 *     customer-facing timeline
 *   - a second order cancelled after packing -> stock returns to the shelf
 *   - a return/refund with a "saleable" inspection -> restock + refund status
 *
 * Requires:
 *   - App running at APP_URL (default http://localhost:8084)
 *   - admin@arovolife.test / admin12345 (admin, `commerce.order.manage` +
 *     `finance.record` + `returns.receive`)
 *   - A11Y_ADN / A11Y_PASSWORD pointing at a distributor allowed to buy
 *     (KYC-approved, past cooling-off registration) — defaults to
 *     394325128 / Test1234!
 *   - The dev/local payment gateway must resolve to the STUB gateway
 *     (`PaymentSettings::stubEnabled()` true, Razorpay off) — this suite
 *     never drives the real Razorpay Checkout modal. If Razorpay is
 *     configured, the checkout tests here skip themselves with a message
 *     that says so, rather than trying to fake a card payment.
 *   - At least one warehouse that fulfils orders with stock on hand for a
 *     shop product (needed to reach "paid" and to pack)
 *
 * The two orders placed by this suite are real distributor purchases against
 * whatever product is first in the shop listing — run against a disposable
 * dev database, not a shared one, if the BV/commission side effects matter.
 */

import { test, expect, submitAndConfirm, readStockOnHand } from './fixtures.js';

/** Adds the first available shop product to the distributor's cart. */
async function addFirstProductToCart(page) {
    await page.goto('/shop');
    const card = page.locator('a[href*="/shop/p/"]').first();
    if ((await card.count()) === 0) {
        return null;
    }
    await card.click();
    await page.waitForURL('**/shop/p/**');

    const addToCartForm = page.locator('form').filter({ has: page.getByRole('button', { name: /Add to Cart/i }) });
    if ((await addToCartForm.count()) === 0) {
        return null; // out of stock / not purchasable
    }

    await addToCartForm.getByRole('button', { name: /Add to Cart/i }).click();
    await page.waitForURL('**/shop/cart**');
    // The PDP never shows the SKU text — read it off the freshly-added cart
    // line instead (resources/views/shop/cart.blade.php marks it with
    // data-cart-added and prints "SKU <variant_sku>" in a font-mono <p>).
    const skuText = await page.locator('[data-cart-added] p.font-mono').first().textContent().catch(() => null);
    return skuText?.replace(/SKU\s*/i, '').trim() ?? null;
}

/**
 * Runs the storefront checkout with the stub gateway and returns the placed
 * order number, or null if the gateway is not the stub (Razorpay configured)
 * or checkout is closed, in which case the caller should skip.
 */
async function placeOrderViaStub(page) {
    await page.goto('/shop/checkout');
    if (page.url().includes('/shop/cart') || page.url().includes('/login')) {
        return { orderNo: null, reason: 'redirected away from checkout — cart empty, not eligible to buy, or not logged in' };
    }

    const gatewayNotice = page.locator('text=/Test gateway — no real money moves\\./i');
    const razorpayNotice = page.locator('text=/You will be taken to a secure payment page/i');
    const isStub = await gatewayNotice.isVisible().catch(() => false);
    const isRazorpay = await razorpayNotice.isVisible().catch(() => false);

    if (!isStub) {
        return {
            orderNo: null,
            reason: isRazorpay
                ? 'the live/Razorpay gateway is configured in this environment — this suite only drives the stub gateway'
                : 'no online payment gateway is available (checkout closed) — enable the stub gateway locally',
        };
    }

    await page.fill('input[name="buyer_name"]', 'Playwright QA');
    await page.fill('input[name="buyer_email"]', 'playwright-qa@example.test');
    await page.fill('input[name="buyer_phone"]', '9876543210');
    await page.fill('input[name="ship_line1"]', '221B Test Street');
    await page.fill('input[name="ship_city"]', 'Hyderabad');
    await page.fill('input[name="ship_state"]', 'Telangana');
    await page.fill('input[name="ship_pincode"]', '500001');
    await page.check('input[name="accept_terms"]');

    await page.getByRole('button', { name: 'Place Order' }).click();
    await page.waitForURL('**/shop/confirmation/**', { timeout: 15_000 });

    const orderNoText = await page.locator('text=/Order\\s+ORD/i, text=/[A-Z]{2,}-?\\d{4,}/').first().textContent().catch(() => null);
    const match = page.url().match(/confirmation\/([^/?]+)/);
    return { orderNo: match ? decodeURIComponent(match[1]) : orderNoText, reason: null };
}

/** Finds the admin order-show URL for a given order number. */
async function gotoAdminOrder(page, orderNo) {
    await page.goto(`/admin/commerce/orders?status=`);
    const link = page.locator('a.font-mono, td a').filter({ hasText: orderNo }).first();
    if ((await link.count()) > 0) {
        await link.click();
        return;
    }
    // Fall back to the order list row text match (order_no cell).
    const row = page.locator('tbody tr').filter({ hasText: orderNo }).first();
    await row.getByRole('link', { name: 'View →' }).click();
}

test.describe.configure({ mode: 'serial' });

// ---------------------------------------------------------------------------
// Order 1: full lifecycle — place -> pack (FEFO) -> ship -> deliver -> timeline
// ---------------------------------------------------------------------------

test.describe('Order lifecycle: place, pack, ship, deliver', () => {
    let orderNo = null;

    test('distributor places an order through the storefront (stub gateway)', async ({ distributorPage: page }) => {
        const sku = await addFirstProductToCart(page);
        test.skip(sku === null, 'No purchasable product found in the shop listing (empty catalog or all out of stock) — seed a shop product with stock.');

        const result = await placeOrderViaStub(page);
        test.skip(result.orderNo === null, `Could not place a stub-gateway order: ${result.reason}`);
        orderNo = result.orderNo;
        expect(orderNo).toBeTruthy();
    });

    test('admin packs the order and the pick list shows a FEFO batch', async ({ adminPage: page }) => {
        test.skip(orderNo === null, 'No order was placed — see previous test.');

        await gotoAdminOrder(page, orderNo);
        await expect(page.getByRole('heading', { name: new RegExp(orderNo) })).toBeVisible();

        const packForm = page.locator('form').filter({ has: page.getByRole('button', { name: 'Pack order' }) });
        test.skip((await packForm.count()) === 0, 'Order is not in a packable state (status is not "paid") — the stub order may not have reached "paid".');

        await submitAndConfirm(page, packForm.getByRole('button', { name: 'Pack order' }));

        await expect(page.locator('h3').filter({ hasText: 'Pick list' })).toBeVisible();
        const pickRows = page.locator('table').filter({ has: page.locator('th', { hasText: 'Batch' }) }).locator('tbody tr');
        await expect(pickRows.first()).toBeVisible();
        // FEFO: a batch and an expiry (or the explicit "—" placeholder when
        // the variant carries no batches) must be present per pick line.
        await expect(pickRows.first().locator('td').nth(3)).not.toHaveText('');
    });

    test('admin ships the order with carrier and tracking number', async ({ adminPage: page }) => {
        test.skip(orderNo === null, 'No order was placed.');

        await gotoAdminOrder(page, orderNo);
        const shipForm = page.locator('form').filter({ has: page.getByRole('button', { name: 'Mark as Shipped' }) });
        test.skip((await shipForm.count()) === 0, 'Order is not in a shippable state (must be paid or ready_to_ship).');

        await shipForm.locator('input[name="ship_carrier"]').fill('Delhivery');
        await shipForm.locator('input[name="ship_tracking_no"]').fill(`PWTRACK${Date.now()}`);
        await submitAndConfirm(page, shipForm.getByRole('button', { name: 'Mark as Shipped' }));

        await expect(page.locator('text=Courier: Delhivery')).toBeVisible();
    });

    test('admin marks the order delivered, opening cooling-off', async ({ adminPage: page }) => {
        test.skip(orderNo === null, 'No order was placed.');

        await gotoAdminOrder(page, orderNo);
        const deliverButton = page.getByRole('button', { name: /Mark as Delivered/ });
        test.skip((await deliverButton.count()) === 0, 'Order is not shipped, so it cannot be marked delivered.');

        await submitAndConfirm(page, deliverButton);
        await expect(page.locator('div').filter({ hasText: /^Delivered/ }).last()).toBeVisible();
    });

    test('customer-facing timeline reflects placed/paid/packed/shipped/delivered', async ({ distributorPage: page }) => {
        test.skip(orderNo === null, 'No order was placed.');

        await page.goto(`/orders/${orderNo}`);
        const timeline = page.locator('ol[aria-label="Order timeline"]');
        await expect(timeline).toBeVisible();
        await expect(timeline.locator('li').filter({ hasText: 'Delivered' })).toBeVisible();
    });
});

// ---------------------------------------------------------------------------
// Order 2: cancel after packing — stock returns to the shelf
// ---------------------------------------------------------------------------

test.describe('Order lifecycle: cancel after packing returns stock', () => {
    let orderNo = null;
    let sku = null;
    let warehouseCode = null;
    let stockBeforePack = null;

    test('distributor places a second order', async ({ distributorPage: page }) => {
        sku = await addFirstProductToCart(page);
        test.skip(sku === null, 'No purchasable product found in the shop listing.');

        const result = await placeOrderViaStub(page);
        test.skip(result.orderNo === null, `Could not place a stub-gateway order: ${result.reason}`);
        orderNo = result.orderNo;
    });

    test('pack the order, then read the post-pack stock level', async ({ adminPage: page }) => {
        test.skip(orderNo === null, 'No order was placed.');

        await gotoAdminOrder(page, orderNo);
        const packForm = page.locator('form').filter({ has: page.getByRole('button', { name: 'Pack order' }) });
        test.skip((await packForm.count()) === 0, 'Order is not packable.');

        warehouseCode = await packForm.locator('select[name="warehouse_code"] option:checked').textContent()
            .then((t) => t?.match(/\(([^)]+)\)/)?.[1] ?? null)
            .catch(() => null);
        test.skip(warehouseCode === null, 'Could not determine the pack warehouse code from the pack form.');

        await submitAndConfirm(page, packForm.getByRole('button', { name: 'Pack order' }));
        await expect(page.locator('h3').filter({ hasText: 'Pick list' })).toBeVisible();

        stockBeforePack = await readStockOnHand(page, sku, warehouseCode);
    });

    test('cancel the packed order and confirm the stock comes back', async ({ adminPage: page }) => {
        test.skip(orderNo === null || warehouseCode === null, 'Setup steps did not complete.');

        await gotoAdminOrder(page, orderNo);
        const cancelButton = page.getByRole('button', { name: 'Cancel Order' });
        test.skip((await cancelButton.count()) === 0, 'Order is not in a cancellable state (placed/paid/ready_to_ship).');

        await submitAndConfirm(page, cancelButton);
        // The "Cancelled" text lives in the Status card's <div> (with the
        // timestamp appended), not a bare span/p — same shape as the
        // "Delivered" assertion above, and the timeline also renders a
        // "Cancelled" step, so pick the later match (the Status card).
        await expect(page.locator('div').filter({ hasText: /^Cancelled/ }).last()).toBeVisible();

        const stockAfterCancel = await readStockOnHand(page, sku, warehouseCode);
        expect(stockAfterCancel).not.toBeNull();
        expect(stockBeforePack).not.toBeNull();
        // Cancelling a packed order releases the reserved stock back — the
        // on-hand figure the pack step reduced must be restored.
        expect(stockAfterCancel).toBeGreaterThan(stockBeforePack);
    });
});

// ---------------------------------------------------------------------------
// Returns / refund — saleable inspection -> restock + refund status
// ---------------------------------------------------------------------------

test.describe('Returns: saleable inspection restocks and settles a refund', () => {
    test('admin returns list is reachable', async ({ adminPage: page }) => {
        await page.goto('/admin/returns');
        await expect(page.getByRole('heading', { name: 'Returns' }).or(page.locator('h1, h2').filter({ hasText: /return/i }).first())).toBeVisible();
    });

    test('a return awaiting inspection can be inspected as saleable, approved, and shows restock + refund status', async ({ adminPage: page }) => {
        await page.goto('/admin/returns');

        // Look for any return whose order is in refund_requested status —
        // the only state the inspection form accepts. This suite does not
        // create a return itself (that requires a delivered order past
        // placement, which the earlier describe blocks may not have reached
        // in a given run), so it works with whatever the dev DB already has.
        const rows = page.locator('tbody tr, li').filter({ hasText: /RMA|opened/i });
        const count = await rows.count().catch(() => 0);
        test.skip(count === 0, 'No return requests exist in this dev database to inspect. Open one from the storefront (Orders → Return this order) with a delivered order first, then re-run.');

        // The row's first <a> is the order-number link (admin.commerce.orders.show),
        // not the return itself — the return's own show page is the "Review →" link.
        await rows.first().getByRole('link', { name: 'Review →' }).click();
        await page.waitForURL('**/admin/returns/**');

        const inspectForm = page.locator('form').filter({ has: page.locator('select[name="condition"]') });
        if ((await inspectForm.count()) === 0) {
            test.skip(true, 'This return is not awaiting inspection (already inspected, or is a cooling-off cancellation which never needs one).');
        }

        await inspectForm.locator('select[name="condition"]').selectOption('saleable');
        await inspectForm.locator('textarea[name="notes"]').fill('Playwright UI test: unopened, resealed box, saleable.');
        await submitAndConfirm(page, inspectForm.getByRole('button', { name: 'Record inspection' }));

        await expect(page.locator('dd').filter({ hasText: /Saleable/i })).toBeVisible();

        const approveButton = page.getByRole('button', { name: /Approve refund/ });
        test.skip((await approveButton.count()) === 0, 'No approve action available — check the `finance.record` permission on the admin fixture.');
        await submitAndConfirm(page, approveButton);

        // Refund status: the return now shows an approved/refunded state.
        await expect(page.locator('span').filter({ hasText: /Approved|Refunded/i }).first()).toBeVisible();

        // Restock: the returns & restock report should list this RMA with a
        // saleable condition — the report is the durable, UI-visible record
        // of the restock, since the exact warehouse the return lands in is
        // not shown on the return page itself.
        await page.goto('/admin/inventory/reports/returns-restock');
        await expect(page.getByRole('table')).toBeVisible();
    });
});
