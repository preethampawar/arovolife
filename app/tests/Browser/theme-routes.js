/**
 * The route inventory the dark-mode contrast sweep walks.
 *
 * Built from `php artisan route:list --method=GET --except-vendor`, dropping
 * anything with a REQUIRED `{parameter}` and everything under `api/`. A route
 * whose only parameters are optional — `tree/{adn?}`, `admin/tree/{id?}` — is
 * reachable bare and belongs here; leaving those out is how the genealogy
 * pages, and the two modals that only exist on them, went unswept. It is checked in
 * as a literal list on purpose: generating it at test time would make the
 * suite unable to enumerate its own cases until the app boots, and a route
 * that silently disappears would then show up as a smaller run rather than a
 * failure.
 *
 * Grouped by the fixture that may load it — see the permission matrix in
 * docs/plans/shared-components-and-app-theme-2026-09-14.md. A guest cannot
 * reach the portal and a distributor cannot reach the console, so one login
 * cannot sweep the application.
 *
 * When a route is added, add it here too. The count assertion in
 * theme-sweep.spec.js is what tells you that you forgot.
 */

/**
 * Step 1 of the wizard resolves the referral link and forwards to step 2, so
 * a bare /register is a redirect to Contact Us ("referral_link_required") and
 * the wizard is not reachable without a sponsor. The ADN is an env override
 * for the same reason the fixtures' one is: the dev database is reseeded, and
 * a stale constant would quietly turn into a redirect — which the expected-
 * redirect assertion in theme-sweep.spec.js is there to catch.
 *
 * It must be a distributor with a free placement leg, or step 1 bounces with
 * "placement_full". Visiting the link creates no data; it only puts the
 * sponsor and placement in the session.
 */
const WIZARD_SPONSOR = process.env.THEME_WIZARD_ADN ?? '954454971';

/**
 * Reachable signed out. The `page` fixture.
 *
 * Order matters in one place: /register must precede /register/account,
 * because it is what puts the wizard intent in the session.
 */
export const PUBLIC_ROUTES = [
    '/',
    '/about-us',
    '/arovo-hub',
    '/blogs',
    '/compliance-documents',
    '/contact-us',
    '/faq',
    '/find-my-id',
    '/forgot-password',
    '/grievance/track',
    '/join',
    '/login',
    '/news',
    '/p/grievance/form',
    '/p/grievance/submitted',
    `/register?sponsor=${WIZARD_SPONSOR}&placement=${WIZARD_SPONSOR}`,
    '/register/account',
    '/seminars',
    '/shop',
    '/shop/cart',
    '/shop/checkout',
];

/**
 * Signed in as a distributor. The `distributorPage` fixture.
 */
export const DISTRIBUTOR_ROUTES = [
    '/addresses',
    '/announcements',
    '/arete-centres',
    '/bv-ledger',
    '/cooling-off',
    '/dashboard',
    '/dashboard/direct-seller-application',
    '/dashboard/documents',
    '/dashboard/profile-stats',
    '/dashboard/tax-statements',
    '/help',
    '/income',
    '/income/adc-bonus',
    '/income/fortune-bonus',
    '/income/genos-bv',
    '/income/genos-ledger',
    '/income/growth-booster',
    '/income/gsb-history',
    '/income/mentorship',
    '/income/rank-bonus',
    '/income/wallet',
    '/line-change',
    '/messages',
    '/my-business',
    '/my/arete-centre',
    '/my/arete-centre/apply',
    '/my/arete-centre/edit',
    '/my/grievances',
    '/my/grievances/create',
    '/my/offers',
    '/my/requests',
    '/my/requests/create',
    '/notifications',
    '/orders',
    '/orders/sales',
    '/profile',
    '/profile/bank',
    '/profile/consents',
    '/profile/password',
    '/profile/withdraw-consent',
    '/tree',
    '/tree/sponsorship',
];

/**
 * The console. The `adminPage` fixture.
 */
export const ADMIN_ROUTES = [
    '/admin',
    '/admin/action-center',
    '/admin/analytics',
    '/admin/announcements',
    '/admin/announcements/create',
    '/admin/arete-centres',
    '/admin/arete-centres/applications',
    '/admin/arete-centres/create',
    '/admin/audit-log',
    '/admin/catalog/banners',
    '/admin/catalog/banners/create',
    '/admin/catalog/categories',
    '/admin/catalog/categories/create',
    '/admin/catalog/products',
    '/admin/catalog/products/create',
    '/admin/commerce/bv-ledger',
    '/admin/commerce/coupons',
    '/admin/commerce/coupons/create',
    '/admin/commerce/offers',
    '/admin/commerce/orders',
    '/admin/compensation',
    '/admin/compensation/adc-bonus',
    '/admin/compensation/adc-bonus/applications',
    '/admin/compensation/adc-bonus/centers',
    '/admin/compensation/adc-calculation',
    '/admin/compensation/aw-rw-calculation',
    '/admin/compensation/carry-forwards',
    '/admin/compensation/daily-cutoffs',
    '/admin/compensation/engine-runs',
    '/admin/compensation/engine-runs/events',
    '/admin/compensation/fb-calculation',
    '/admin/compensation/fortune-bonus',
    '/admin/compensation/gbb',
    '/admin/compensation/gbb-calculation',
    '/admin/compensation/gbb-input-output',
    '/admin/compensation/genos-transactions',
    '/admin/compensation/gsb-calculation',
    '/admin/compensation/gsb-input-output',
    '/admin/compensation/manual-controls',
    '/admin/compensation/monthly-payouts',
    '/admin/compensation/msb-calculation',
    '/admin/compensation/msb-input-output',
    '/admin/compensation/payout-settings',
    '/admin/compensation/personal-bv-topups',
    '/admin/compensation/plan-settings',
    '/admin/compensation/rank-bonus',
    '/admin/compensation/rb-calculation',
    '/admin/compensation/rb-input-output',
    '/admin/compensation/weekly-payouts',
    '/admin/compliance-documents',
    '/admin/contact-inquiries',
    '/admin/content',
    '/admin/content/create',
    '/admin/distributor-requests',
    '/admin/distributors',
    '/admin/distributors/create',
    '/admin/dormancy',
    '/admin/feature-flags',
    '/admin/grievances',
    '/admin/grievances/create',
    '/admin/grievances/report',
    '/admin/help',
    '/admin/inventory/adjustments',
    '/admin/inventory/adjustments/create',
    '/admin/inventory/grns',
    '/admin/inventory/grns/create',
    '/admin/inventory/purchase-orders',
    '/admin/inventory/purchase-orders/create',
    '/admin/inventory/reports',
    '/admin/inventory/reports/batch-expiry',
    '/admin/inventory/reports/low-stock',
    '/admin/inventory/reports/movements',
    '/admin/inventory/reports/order-fulfilment',
    '/admin/inventory/reports/purchase-register',
    '/admin/inventory/reports/returns-restock',
    '/admin/inventory/reports/stock-in-out',
    '/admin/inventory/reports/stock-on-hand',
    '/admin/inventory/reports/transfer-register',
    '/admin/inventory/reports/valuation',
    '/admin/inventory/stock',
    '/admin/reports/profit',
    '/admin/reports/profit/by-category',
    '/admin/reports/profit/by-product',
    '/admin/reports/profit/register',
    '/admin/reports/profit/summary',
    '/admin/inventory/suppliers',
    '/admin/inventory/suppliers/create',
    '/admin/inventory/transfers',
    '/admin/inventory/transfers/create',
    '/admin/inventory/warehouses',
    '/admin/inventory/warehouses/create',
    '/admin/kyc',
    '/admin/lifetime-awards',
    '/admin/lifetime-awards/catalog',
    '/admin/line-changes',
    '/admin/messaging/reports',
    '/admin/payments',
    '/admin/payments/refunds',
    '/admin/returns',
    '/admin/settings',
    '/admin/staff',
    '/admin/staff/create',
    '/admin/tree',
];

/**
 * Wizard steps 3-12, in the order a joiner walks them.
 *
 * Not in PUBLIC_ROUTES because none of them is reachable by navigation alone:
 * EnsureRegistrationProgress compares the requested step against the furthest
 * one the session has completed, so a fresh visitor asking for step 7 is sent
 * back to step 3. theme-wizard.spec.js sweeps these by actually walking the
 * wizard — see the header there for why that is safe to run against a real
 * database.
 */
export const WIZARD_ROUTES = [
    '/register/orientation',        // 3
    '/register/consent',            // 4
    '/register/identity-documents', // 5
    '/register/demographics',       // 6
    '/register/nominee',            // 7
    '/register/kyc/bank',           // 8
    '/register/personal',           // 9
    '/register/documents',          // 10
    '/register/arete-centre',       // 11
    '/register/complete',           // 12
];

/**
 * Route -> reason. Everything here is deliberately not swept, and the reason
 * is the point: a silent omission and a considered exclusion look identical
 * in a passing test run.
 */
export const SKIP = {
    '/admin/commerce/bv-ledger/export': 'returns a file, not a page',
    '/admin/compensation/adc-calculation/export': 'returns a file, not a page',
    '/admin/compensation/aw-rw-calculation/export': 'returns a file, not a page',
    '/admin/compensation/carry-forwards/export': 'returns a file, not a page',
    '/admin/compensation/daily-cutoffs/export': 'returns a file, not a page',
    '/admin/compensation/engine-runs/recompute-progress': 'JSON endpoint, no markup to scan',
    '/admin/compensation/fb-calculation/export': 'returns a file, not a page',
    '/admin/compensation/gbb-calculation/export': 'returns a file, not a page',
    '/admin/compensation/gbb-input-output/export': 'returns a file, not a page',
    '/admin/compensation/genos-transactions/export': 'returns a file, not a page',
    '/admin/compensation/gsb-calculation/export': 'returns a file, not a page',
    '/admin/compensation/gsb-input-output/export': 'returns a file, not a page',
    '/admin/compensation/msb-calculation/export': 'returns a file, not a page',
    '/admin/compensation/msb-input-output/export': 'returns a file, not a page',
    '/admin/compensation/personal-bv-topups/export': 'returns a file, not a page',
    '/admin/compensation/rb-calculation/export': 'returns a file, not a page',
    '/admin/compensation/rb-input-output/export': 'returns a file, not a page',
    '/admin/distributors/export': 'returns a file, not a page',
    '/admin/grievances/report/export': 'returns a file, not a page',
    '/admin/tree/search': 'JSON endpoint, no markup to scan',
    '/admin/tree/suggest': 'JSON endpoint, no markup to scan',
    '/dashboard/membership-card': 'print surface — forced light by design',
    '/income/gsb-history/export': 'returns a file, not a page',
    '/income/wallet/export': 'returns a file, not a page',
    '/join/lookup': 'JSON endpoint, no markup to scan',
    '/kyc/resubmit': 'only reachable while an applicant is rejected; the fixture is active',
    '/register/check-availability': 'JSON endpoint, no markup to scan',
    '/tree/search': 'JSON endpoint, no markup to scan',
    '/tree/suggest': 'JSON endpoint, no markup to scan',
};

export const ALL_ROUTES = [...PUBLIC_ROUTES, ...DISTRIBUTOR_ROUTES, ...ADMIN_ROUTES, ...WIZARD_ROUTES];
