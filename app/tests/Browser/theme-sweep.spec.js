/**
 * Dark mode, over the whole route inventory.
 *
 * theme.spec.js proves the toggle works and scans eight pages. Eight pages is
 * a sample, and the failure mode this theme actually has is not "the toggle
 * broke" — it is one utility somewhere with no `html.dark` rule, which leaves
 * light text on a light ground at 1.05:1 on exactly the page nobody sampled.
 * So this walks every parameterless GET route instead, in dark, and lets axe
 * score the result.
 *
 * One test per tier rather than one per route: the fixtures log in per test,
 * and 156 logins would cost more than the sweep itself. The trade is that a
 * failure has to name its own routes, so the reporting below groups the
 * violations by colour pair and prints the routes each pair appears on — the
 * shipped work found 31 failing nodes that were five root causes, and
 * per-node output was unreadable.
 *
 * A route that redirects is not a failure (a distributor route the fixture
 * cannot reach, a checkout with an empty cart): the landed page is recorded
 * and left unscanned, because it is swept under its own name elsewhere.
 */

import AxeBuilder from '@axe-core/playwright';
import { test, expect } from './fixtures.js';
import { PUBLIC_ROUTES, DISTRIBUTOR_ROUTES, ADMIN_ROUTES, SKIP, ALL_ROUTES } from './theme-routes.js';

const KEY = 'arovolife_theme';

/** The sweep is the slow spec in the suite; it must not also be the flaky one. */
test.describe.configure({ timeout: 15 * 60 * 1000 });

const pathOf = (url) => new URL(url).pathname.replace(/\/$/, '') || '/';

/**
 * The public pages fade their sections in on scroll ([data-reveal] starts at
 * opacity 0 with a 700ms transition), and axe composites opacity into the
 * colours it scores — so a scan that lands mid-animation reports white text
 * at 1.15:1 and calls it a contrast failure. Settling the page rather than
 * sleeping through it also means the sections below the fold get scanned at
 * all, which is more coverage, not less.
 */
const SETTLE = `
    *, *::before, *::after { transition: none !important; animation: none !important; }
    [data-reveal] { opacity: 1 !important; transform: none !important; }
`;

async function goDark(page, path) {
    await page.goto(path);
    await page.evaluate(([k, v]) => localStorage.setItem(k, v), [KEY, 'dark']);
    await page.reload();
}

/**
 * Walks one tier. Returns { scanned, redirected, notDark, violations } where
 * violations carry the route they were found on.
 */
async function sweep(page, routes) {
    const out = { scanned: [], redirected: [], notDark: [], violations: [] };

    await goDark(page, routes[0]);

    for (const path of routes) {
        await page.goto(path);
        const landed = pathOf(page.url());
        if (landed !== pathOf(new URL(path, 'http://x'))) {
            out.redirected.push(`${pathOf(new URL(path, 'http://x'))} -> ${landed}`);
            continue;
        }

        // A page that renders light in dark mode has lost the pre-paint
        // script, which is a real defect and not an axe concern.
        if (!(await page.evaluate(() => document.documentElement.classList.contains('dark')))) {
            out.notDark.push(path);
            continue;
        }

        await page.addStyleTag({ content: SETTLE });
        const results = await new AxeBuilder({ page }).withRules(['color-contrast']).analyze();
        for (const v of results.violations) {
            for (const node of v.nodes) {
                out.violations.push({ path, target: node.target.join(' '), data: node.any?.[0]?.data ?? {} });
            }
        }
        out.scanned.push(path);
    }

    return out;
}

/** Collapse per-node noise into the handful of colour pairs behind it. */
function report(result) {
    const groups = new Map();
    for (const v of result.violations) {
        const d = v.data;
        const key = `${d.fgColor} on ${d.bgColor} — ${d.contrastRatio}:1 (${d.fontSize}, weight ${d.fontWeight})`;
        if (!groups.has(key)) {
            groups.set(key, { routes: new Set(), example: v.target, count: 0 });
        }
        const g = groups.get(key);
        g.routes.add(v.path);
        g.count += 1;
    }

    return [...groups.entries()]
        .sort((a, b) => b[1].count - a[1].count)
        .map(([key, g]) =>
            `${key}\n    ${g.count} node(s) on ${g.routes.size} route(s): ${[...g.routes].slice(0, 6).join(', ')}` +
            `${g.routes.size > 6 ? ` +${g.routes.size - 6} more` : ''}\n    e.g. ${g.example}`)
        .join('\n\n');
}

async function assertClean(page, routes, testInfo, expectedRedirects) {
    const result = await sweep(page, routes);

    await testInfo.attach('sweep.json', {
        body: JSON.stringify(result, null, 2),
        contentType: 'application/json',
    });
    // eslint-disable-next-line no-console
    console.log(`  swept ${result.scanned.length}/${routes.length} routes in dark` +
        `${result.redirected.length ? `, ${result.redirected.length} redirected` : ''}`);

    expect(result.notDark, 'every page must honour the stored theme before paint').toEqual([]);

    // A redirect costs coverage, so it has to be a known one. Without this an
    // expired fixture or a new guard turns the sweep into a shorter sweep that
    // still passes, which is the failure this whole spec exists to avoid.
    expect(result.redirected.sort(), 'unexpected redirect — the route was not scanned')
        .toEqual([...expectedRedirects].sort());

    expect(report(result), `contrast failures in dark across ${result.scanned.length} routes`).toBe('');
}

/**
 * Redirects that are correct behaviour, not lost coverage. Anything not on
 * these lists fails the sweep — see the assertion in assertClean.
 */
const DISTRIBUTOR_REDIRECTS = [
    '/my/arete-centre/edit -> /my/arete-centre',   // the fixture has no application to edit
];
const ADMIN_REDIRECTS = [
    // Legacy aliases; both land on pages this sweep already covers.
    '/admin/compensation/adc-bonus/applications -> /admin/arete-centres/applications',
    '/admin/compensation/adc-bonus/centers -> /admin/arete-centres',
];

test.describe('dark mode holds across the route inventory', () => {
    test('every public route', async ({ page }, testInfo) => {
        await assertClean(page, PUBLIC_ROUTES, testInfo, [
            '/p/grievance/submitted -> /p/grievance/form',   // needs a submission in session
            '/register -> /register/account',                // step 1 resolves the link and forwards
            '/shop/checkout -> /login',                      // guest checkout is off by default
        ]);
    });

    test('every distributor route', async ({ distributorPage: page }, testInfo) => {
        await assertClean(page, DISTRIBUTOR_ROUTES, testInfo, DISTRIBUTOR_REDIRECTS);
    });

    test('every admin route', async ({ adminPage: page }, testInfo) => {
        await assertClean(page, ADMIN_ROUTES, testInfo, ADMIN_REDIRECTS);
    });
});

test.describe('the inventory itself', () => {
    test('covers every parameterless GET route, or says why not', () => {
        // Refresh with:
        //   php artisan route:list --method=GET --except-vendor --json
        // dropping any URI containing '{' and everything under api/.
        // This assertion is the tripwire: add a route, and it fails until the
        // route is either swept or given a reason in SKIP. ALL_ROUTES counts
        // the wizard steps too — they are unreachable by navigation and are
        // swept by theme-wizard.spec.js, which walks the wizard to get to
        // them, so they are covered rather than excused.
        expect(ALL_ROUTES.length + Object.keys(SKIP).length).toBe(205);
        expect(new Set(ALL_ROUTES).size, 'no route listed in two tiers').toBe(ALL_ROUTES.length);
        for (const path of ALL_ROUTES) {
            expect(SKIP[path], `${path} is both swept and skipped`).toBeUndefined();
        }
    });

    test('a guest sent to a distributor route lands on login, not a themed error', async ({ page }) => {
        await goDark(page, '/');
        await page.goto('/income/wallet');
        expect(pathOf(page.url())).toBe('/login');
        expect(await page.evaluate(() => document.documentElement.classList.contains('dark'))).toBe(true);
    });
});

test.afterEach(async ({ page }) => {
    // Never leave the shared dev browser profile dark for the next spec.
    await page.evaluate((k) => localStorage.removeItem(k), KEY).catch(() => {});
});
