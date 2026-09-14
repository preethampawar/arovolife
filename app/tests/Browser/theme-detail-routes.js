/**
 * The other half of the route inventory: GET routes that take a required
 * `{parameter}`.
 *
 * theme-routes.js deliberately covers only routes reachable bare, and said so.
 * That left 68 routes unswept — every detail, edit and show page in the
 * application — and the omission was not neutral: all three pages found
 * returning 500 in the first sweep were on pages like these, reached by hand
 * because the sweep could not reach them.
 *
 * HOW THE IDS ARE RESOLVED
 *
 * Not by seeding, and not by pinning an id. Seeded ids rot the first time the
 * dev database is rebuilt, and the failure reads as a broken page rather than
 * a stale fixture. Instead each entry names a list page already in the sweep
 * and the shape of the link it wants: the spec loads the list, takes the first
 * matching href, and follows it. The id is therefore whatever the database
 * actually holds, on any database, and a page with no rows yet is reported as
 * unresolved rather than quietly skipped.
 *
 * `route`  - the route:list URI this entry covers; the count assertion in
 *            theme-detail.spec.js reconciles these against the real list
 * `from`   - a list page, in the same tier, that links to this one
 * `hop`    - optional. Some edit screens are linked from the detail page, not
 *            the list, so the resolver follows this href first and looks for
 *            `match` on the page it lands on
 * `match`  - regex the href must match; the first match on the page wins
 * `about`  - what the route is, for the failure message
 */

/** @type {{from: string, match: RegExp, about: string}[]} */
export const PUBLIC_DETAILS = [
    { from: '/compliance-documents', match: /^\/p\/[a-z0-9-]+$/, route: '/p/{slug}', about: '/p/{slug} — a content page' },
    { from: '/shop', match: /^\/shop\/p\/[a-z0-9-]+$/, route: '/shop/p/{slug}', about: '/shop/p/{slug} — a product' },
];

/** @type {{from: string, match: RegExp, about: string}[]} */
export const DISTRIBUTOR_DETAILS = [
    { from: '/announcements', match: /^\/announcements\/\d+$/, route: '/announcements/{announcement}', about: '/announcements/{id}' },
    { from: '/messages', match: /^\/messages\/\d+$/, route: '/messages/{user}', about: '/messages/{user} — a thread' },
    { from: '/my/grievances', match: /^\/my\/grievances\/\d+$/, route: '/my/grievances/{id}', about: '/my/grievances/{id}' },
    { from: '/my/requests', match: /^\/my\/requests\/\d+$/, route: '/my/requests/{distributorRequest}', about: '/my/requests/{id}' },
    { from: '/orders', match: /^\/orders\/[A-Za-z0-9-]+$/, route: '/orders/{orderNo}', about: '/orders/{orderNo}' },
    { from: '/orders/sales', match: /^\/orders\/sales\/[A-Za-z0-9-]+$/, route: '/orders/sales/{orderNo}', about: '/orders/sales/{orderNo}' },
];

/** @type {{from: string, match: RegExp, about: string}[]} */
export const ADMIN_DETAILS = [
    { from: '/admin/action-center', match: /^\/admin\/action-center\/[a-z_.-]+$/, route: '/admin/action-center/{key}', about: '/admin/action-center/{key}' },
    { from: '/admin/announcements', match: /^\/admin\/announcements\/\d+\/edit$/, route: '/admin/announcements/{announcement}/edit', about: 'announcement edit' },
    { from: '/admin/arete-centres', match: /^\/admin\/arete-centres\/\d+\/edit$/, route: '/admin/arete-centres/{center}/edit', about: 'Arete centre edit' },
    { from: '/admin/arete-centres/applications?status=all', match: /^\/admin\/arete-centres\/applications\/\d+$/, route: '/admin/arete-centres/applications/{application}', about: 'Arete application' },
    { from: '/admin/catalog/banners', match: /^\/admin\/catalog\/banners\/\d+\/edit$/, route: '/admin/catalog/banners/{banner}/edit', about: 'banner edit' },
    { from: '/admin/catalog/categories', match: /^\/admin\/catalog\/categories\/\d+\/edit$/, route: '/admin/catalog/categories/{category}/edit', about: 'category edit' },
    { from: '/admin/catalog/products', match: /^\/admin\/catalog\/products\/\d+\/edit$/, route: '/admin/catalog/products/{product}/edit', about: 'product edit' },
    { from: '/admin/commerce/bv-ledger', match: /^\/admin\/commerce\/bv-ledger\/\d+$/, route: '/admin/commerce/bv-ledger/{distributor}', about: 'BV ledger for one distributor' },
    { from: '/admin/commerce/coupons', match: /^\/admin\/commerce\/coupons\/\d+\/edit$/, route: '/admin/commerce/coupons/{coupon}/edit', about: 'coupon edit' },
    { from: '/admin/commerce/orders', match: /^\/admin\/commerce\/orders\/\d+$/, route: '/admin/commerce/orders/{order}', about: 'order detail' },
    { from: '/admin/compensation/adc-bonus', match: /^\/admin\/compensation\/adc-bonus\/\d{4}-\d{2}$/, route: '/admin/compensation/adc-bonus/{month}', about: 'ADC bonus month' },
    { from: '/admin/compensation/daily-cutoffs', match: /^\/admin\/compensation\/daily-cutoffs\/\d{4}-\d{2}-\d{2}$/, route: '/admin/compensation/daily-cutoffs/{date}', about: 'a daily cut-off' },
    { from: '/admin/compensation/fortune-bonus', match: /^\/admin\/compensation\/fortune-bonus\/\d{4}-\d{2}$/, route: '/admin/compensation/fortune-bonus/{month}', about: 'Fortune Bonus month' },
    { from: '/admin/compensation/gbb', match: /^\/admin\/compensation\/gbb\/\d{4}-\d{2}$/, route: '/admin/compensation/gbb/{month}', about: 'Growth Booster month' },
    { from: '/admin/compensation/monthly-payouts', match: /^\/admin\/compensation\/monthly-payouts\/\d+$/, route: '/admin/compensation/monthly-payouts/{batch}', about: 'monthly payout batch' },
    { from: '/admin/compensation/rank-bonus', match: /^\/admin\/compensation\/rank-bonus\/\d{4}-\d{2}$/, route: '/admin/compensation/rank-bonus/{month}', about: 'Rank Bonus month' },
    { from: '/admin/compensation/weekly-payouts', match: /^\/admin\/compensation\/weekly-payouts\/\d+$/, route: '/admin/compensation/weekly-payouts/{batch}', about: 'weekly payout batch' },
    { from: '/admin/contact-inquiries?filter=all', match: /^\/admin\/contact-inquiries\/\d+$/, route: '/admin/contact-inquiries/{id}', about: 'contact inquiry' },
    { from: '/admin/content', match: /^\/admin\/content\/[a-z0-9-]+\/edit$/, route: '/admin/content/{page}/edit', about: 'content page edit' },
    { from: '/admin/distributor-requests', match: /^\/admin\/distributor-requests\/\d+$/, route: '/admin/distributor-requests/{distributorRequest}', about: 'distributor request' },
    { from: '/admin/distributors', match: /^\/admin\/distributors\/\d+$/, route: '/admin/distributors/{id}', about: 'distributor detail' },
    {
        from: '/admin/distributors',
        hop: /^\/admin\/distributors\/\d+$/,
        match: /^\/admin\/distributors\/\d+\/edit$/,
        route: '/admin/distributors/{id}/edit',
        about: 'distributor edit',
    },
    {
        from: '/admin/distributors',
        hop: /^\/admin\/distributors\/\d+$/,
        match: /^\/admin\/compensation\/distributors\/\d+$/,
        route: '/admin/compensation/distributors/{distributor}',
        about: 'a distributor\'s compensation summary',
    },
    { from: '/admin/grievances', match: /^\/admin\/grievances\/\d+$/, route: '/admin/grievances/{id}', about: 'grievance detail' },
    { from: '/admin/help', match: /^\/admin\/help\/[a-z0-9-]+$/, route: '/admin/help/{slug}', about: 'an admin help page' },
    { from: '/admin/inventory/grns', match: /^\/admin\/inventory\/grns\/\d+$/, route: '/admin/inventory/grns/{purchaseInvoice}', about: 'GRN detail' },
    {
        // Both inventory edit screens are linked from their detail page and
        // only while the document is still editable, so this is a two-hop
        // resolve that can legitimately come up empty.
        from: '/admin/inventory/grns',
        hop: /^\/admin\/inventory\/grns\/\d+$/,
        match: /^\/admin\/inventory\/grns\/\d+\/edit$/,
        route: '/admin/inventory/grns/{purchaseInvoice}/edit',
        about: 'GRN edit',
    },
    { from: '/admin/inventory/purchase-orders', match: /^\/admin\/inventory\/purchase-orders\/\d+$/, route: '/admin/inventory/purchase-orders/{purchaseOrder}', about: 'purchase order detail' },
    {
        from: '/admin/inventory/purchase-orders',
        hop: /^\/admin\/inventory\/purchase-orders\/\d+$/,
        match: /^\/admin\/inventory\/purchase-orders\/\d+\/edit$/,
        route: '/admin/inventory/purchase-orders/{purchaseOrder}/edit',
        about: 'purchase order edit',
    },
    { from: '/admin/inventory/suppliers', match: /^\/admin\/inventory\/suppliers\/\d+\/edit$/, route: '/admin/inventory/suppliers/{supplier}/edit', about: 'supplier edit' },
    { from: '/admin/inventory/transfers', match: /^\/admin\/inventory\/transfers\/\d+$/, route: '/admin/inventory/transfers/{stockTransfer}', about: 'stock transfer detail' },
    { from: '/admin/inventory/warehouses', match: /^\/admin\/inventory\/warehouses\/\d+\/edit$/, route: '/admin/inventory/warehouses/{warehouse}/edit', about: 'warehouse edit' },
    { from: '/admin/kyc', match: /^\/admin\/kyc\/\d+$/, route: '/admin/kyc/{id}', about: 'KYC submission' },
    { from: '/admin/line-changes', match: /^\/admin\/line-changes\/\d+$/, route: '/admin/line-changes/{id}', about: 'line-change request' },
    { from: '/admin/messaging/reports', match: /^\/admin\/messaging\/reports\/\d+$/, route: '/admin/messaging/reports/{report}', about: 'a reported message' },
    { from: '/admin/payments', match: /^\/admin\/payments\/\d+$/, route: '/admin/payments/{intent}', about: 'payment intent' },
    { from: '/admin/returns', match: /^\/admin\/returns\/\d+$/, route: '/admin/returns/{return}', about: 'return detail' },
    { from: '/admin/staff', match: /^\/admin\/staff\/\d+\/edit$/, route: '/admin/staff/{id}/edit', about: 'staff edit' },
];

/**
 * Route pattern -> reason. Same contract as SKIP in theme-routes.js: a
 * considered exclusion and a forgotten route look identical in a green run,
 * so every one of these says which it is.
 */
export const DETAIL_SKIP = {
    // Not pages. A file download, a JSON body, or a redirect.
    '/admin/arete-centres/applications/{application}/documents/{document}': 'streams a stored document',
    '/admin/commerce/bv-ledger/{distributor}/export': 'returns a file, not a page',
    '/admin/compensation/monthly-payouts/{batch}/neft': 'returns a bank file, not a page',
    '/admin/compensation/weekly-payouts/{batch}/neft': 'returns a bank file, not a page',
    '/admin/distributor-requests/{distributorRequest}/documents/{document}': 'streams a stored document',
    '/admin/grievances/{id}/attachments/{attachmentId}': 'streams an attachment',
    '/admin/kyc/{id}/documents/{docId}': 'streams a KYC scan',
    '/compliance-documents/{document}/download': 'returns a file, not a page',
    '/dashboard/team-roster/{scope}': 'JSON endpoint feeding the dashboard roster modal — no markup to scan; the modal itself is swept by theme-overlays.spec.js',
    '/dashboard/team-roster/{scope}/download': 'returns a file, not a page',
    '/distributors/{distributor}/id-card-panel': 'HTML fragment for the tree modal — swept in place by theme-overlays.spec.js',
    '/my/grievances/{id}/attachments/{attachmentId}': 'streams an attachment',
    '/notifications/{id}/open': 'marks read and redirects; the destination is swept under its own name',
    '/orders/{orderNo}/invoice': 'print surface — forced light by design',
    '/shop/pay/{orderNo}/status': 'JSON endpoint, no markup to scan',

    // Reachable only with a token or a state no fixture holds.
    '/activate/{user}': 'signed spouse-activation link; needs a couple registration mid-flight',
    '/kyc/reupload/{document}': 'needs an admin to have flagged a specific document for re-upload',
    '/reset-password/{token}': 'needs a live password-reset token from a mailbox',
    '/shop/easy-cart/{code}': 'needs a live Easy Purchase referral code',

    // Deliberately not exercised.
    '/shop/confirmation/{orderNo}': 'shown once, immediately after checkout; reaching it means placing a real order',
    '/shop/pay/{orderNo}': 'hands off to the payment gateway — never opened by a test run',
    '/orders/{orderNo}/return': 'opens the return flow against a real order; the list page is swept instead',
};

/**
 * Route -> why there is nothing to open on this database.
 *
 * These are routes the inventory covers and the resolver could not reach,
 * because the list behind them is empty. Declared rather than skipped: the
 * entry stays in the inventory, and the day the table has a row the sweep
 * picks the page up — the assertion in theme-detail.spec.js fails first,
 * which is the prompt to delete the line from here.
 *
 * A dev database with more history in it will have fewer of these, and that
 * is the point: nothing here is a permanent exclusion.
 */
export const EXPECTED_UNRESOLVED = {
    '/admin/announcements/{announcement}/edit': 'no announcements published yet',
    '/admin/commerce/coupons/{coupon}/edit': 'no coupons created yet',
    '/admin/compensation/adc-bonus/{month}': 'no ADC bonus month has been calculated yet',
    '/admin/compensation/daily-cutoffs/{date}': 'no daily cut-off has run yet',
    '/admin/distributor-requests/{distributorRequest}': 'no distributor requests raised yet',
    '/admin/grievances/{id}': 'no grievances filed yet',
    '/admin/inventory/grns/{purchaseInvoice}/edit': 'no GRN on the first page is still editable',
    '/admin/inventory/purchase-orders/{purchaseOrder}/edit': 'no purchase order on the first page is still editable',
    '/admin/kyc/{id}': 'the KYC queue is empty in every tab — every distributor here is already active',
    '/admin/line-changes/{id}': 'no line-change requests raised yet',
    '/admin/messaging/reports/{report}': 'no message has been reported yet',
    '/admin/staff/{id}/edit': 'the only staff user is the fixture itself, and a row cannot manage itself',
    '/announcements/{announcement}': 'no announcements published yet',
    '/messages/{user}': 'the fixture has no message thread open',
    '/orders/sales/{orderNo}': 'the fixture has made no sales',
    '/my/grievances/{id}': 'the fixture has filed no grievance',
    '/my/requests/{distributorRequest}': 'the fixture has raised no request',
};

export const ALL_DETAILS = [...PUBLIC_DETAILS, ...DISTRIBUTOR_DETAILS, ...ADMIN_DETAILS];
