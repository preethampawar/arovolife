/**
 * Dark mode across the detail, edit and show pages.
 *
 * theme-sweep.spec.js walks routes that are reachable bare. That is 171 of
 * them, and it was the right place to start, but it left every `{parameter}`
 * route unswept — and those are not a long tail: they are where the work
 * happens. All three pages found returning 500 on the first sweep were pages
 * of this shape, found by hand because no spec could reach them.
 *
 * Ids are resolved by following links rather than seeded or pinned, so this
 * runs against whatever the database happens to hold. See theme-detail-routes.js.
 *
 * A route whose list page has no rows is UNRESOLVED, not skipped: it is
 * reported, and the expected set is asserted, so "no data yet" cannot quietly
 * become "no coverage" the way it would if the resolver just moved on.
 */

import AxeBuilder from '@axe-core/playwright';
import { test, expect } from './fixtures.js';
import {
    PUBLIC_DETAILS, DISTRIBUTOR_DETAILS, ADMIN_DETAILS,
    DETAIL_SKIP, EXPECTED_UNRESOLVED, ALL_DETAILS,
} from './theme-detail-routes.js';

const KEY = 'arovolife_theme';

test.describe.configure({ timeout: 15 * 60 * 1000 });

const pathOf = (url) => new URL(url, 'http://x').pathname.replace(/\/$/, '') || '/';

/** How many detail pages to open looking for a two-hop link before giving up. */
const HOP_CANDIDATES = 5;

/** Same settle sheet as the route sweep — see theme-sweep.spec.js. */
const SETTLE = `
    *, *::before, *::after { transition: none !important; animation: none !important; }
    [data-reveal] { opacity: 1 !important; transform: none !important; }
`;

/** Every in-document href on the current page matching `re`, in document order. */
async function links(page, re) {
    const hrefs = await page.evaluate(() =>
        [...document.querySelectorAll('a[href]')].map((a) => a.getAttribute('href')));

    const origin = new URL(page.url()).origin;
    const found = [];

    for (const href of hrefs) {
        if (href === null || /^(mailto:|tel:|javascript:|#)/i.test(href)) {
            continue;
        }
        // route() emits absolute URLs, so most of these arrive fully qualified.
        // Resolve them and compare the path; only a genuinely different origin
        // is out of scope.
        let url;
        try {
            url = new URL(href, page.url());
        } catch {
            continue;
        }
        if (url.origin !== origin) {
            continue;
        }
        const path = pathOf(url);
        if (re.test(path) && !found.includes(path)) {
            found.push(path);
        }
    }

    return found;
}

async function sweep(page, entries) {
    const out = { scanned: [], unresolved: [], unresolvedFrom: [], notDark: [], broke: [], violations: [] };

    for (const entry of entries) {
        await page.goto(entry.from);

        let target = (await links(page, entry.hop ?? entry.match))[0] ?? null;

        if (entry.hop !== undefined) {
            // The link this entry wants often exists on only some detail pages
            // — an inventory document is editable while it is a draft and not
            // afterwards — so try a few rather than give up on the first row.
            target = null;
            for (const candidate of (await links(page, entry.hop)).slice(0, HOP_CANDIDATES)) {
                await page.goto(candidate);
                target = (await links(page, entry.match))[0] ?? null;
                if (target !== null) {
                    break;
                }
            }
        }

        if (target === null) {
            out.unresolved.push(entry.route);
            out.unresolvedFrom.push(`${entry.route} (nothing matching on ${entry.from})`);
            continue;
        }

        await open(page, entry, target, out);
    }

    return out;
}

/** Loads one resolved page and records what it found there. */
async function open(page, entry, target, out) {
    const response = await page.goto(target);

    // The reason this spec exists. A 500 on a detail page is invisible to a
    // sweep that only walks list pages, and all three found so far were here.
    const status = response?.status() ?? 0;
    if (status >= 400) {
        out.broke.push(`${target} -> HTTP ${status}  (${entry.about})`);

        return;
    }

    if (!(await page.evaluate(() => document.documentElement.classList.contains('dark')))) {
        out.notDark.push(target);

        return;
    }

    await page.addStyleTag({ content: SETTLE });
    const results = await new AxeBuilder({ page }).withRules(['color-contrast']).analyze();

    // A page axe found no text on is indistinguishable from a clean one in the
    // report, and a detail page that rendered empty is worth catching.
    const checked = [...results.passes, ...results.violations, ...results.incomplete]
        .reduce((n, r) => n + r.nodes.length, 0);
    if (checked === 0) {
        out.broke.push(`${target} -> rendered nothing axe could score  (${entry.about})`);

        return;
    }

    for (const v of results.violations) {
        for (const node of v.nodes) {
            out.violations.push({ path: target, target: node.target.join(' '), data: node.any?.[0]?.data ?? {} });
        }
    }
    out.scanned.push(`${entry.route} -> ${target}`);
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

async function assertClean(page, entries, testInfo) {
    const expectedUnresolved = entries
        .map((e) => e.route)
        .filter((route) => EXPECTED_UNRESOLVED[route] !== undefined);

    await page.goto(entries[0].from);
    await page.evaluate(([k, v]) => localStorage.setItem(k, v), [KEY, 'dark']);
    await page.reload();

    const result = await sweep(page, entries);

    await testInfo.attach('detail-sweep.json', {
        body: JSON.stringify(result, null, 2),
        contentType: 'application/json',
    });
    // eslint-disable-next-line no-console
    console.log(`  swept ${result.scanned.length}/${entries.length} detail routes in dark` +
        `${result.unresolved.length ? `, ${result.unresolved.length} with no row to open` : ''}`);

    expect(result.broke, 'a detail page failed to render').toEqual([]);
    expect(result.notDark, 'every page must honour the stored theme before paint').toEqual([]);

    // Same contract as the sweep's redirect list: lost coverage has to be
    // declared, or an emptied table silently shrinks the run.
    // Declared in EXPECTED_UNRESOLVED with a reason, exactly like SKIP. Both
    // directions matter: an undeclared empty list is lost coverage, and a
    // declared one that now resolves is a line to delete.
    expect(result.unresolved.sort(), 'unresolved set changed — see EXPECTED_UNRESOLVED')
        .toEqual([...expectedUnresolved].sort());

    expect(report(result), `contrast failures in dark across ${result.scanned.length} detail routes`).toBe('');
}

test.describe('dark mode holds on the detail pages', () => {
    test('public detail routes', async ({ page }, testInfo) => {
        await assertClean(page, PUBLIC_DETAILS, testInfo);
    });

    test('distributor detail routes', async ({ distributorPage: page }, testInfo) => {
        await assertClean(page, DISTRIBUTOR_DETAILS, testInfo);
    });

    test('admin detail routes', async ({ adminPage: page }, testInfo) => {
        await assertClean(page, ADMIN_DETAILS, testInfo);
    });
});

test.describe('the detail inventory itself', () => {
    test('covers every GET route that takes a parameter, or says why not', () => {
        // Refresh with:
        //   php artisan route:list --method=GET --except-vendor --json
        // keeping URIs that contain '{' and dropping everything under api/.
        const routes = ALL_DETAILS.map((d) => d.route);
        expect(new Set(routes).size, 'two entries claim the same route').toBe(routes.length);
        expect(routes.length + Object.keys(DETAIL_SKIP).length).toBe(68);
        for (const route of routes) {
            expect(DETAIL_SKIP[route], `${route} is both swept and skipped`).toBeUndefined();
        }
        for (const route of Object.keys(EXPECTED_UNRESOLVED)) {
            expect(routes, `${route} is declared unresolved but is not in the inventory`).toContain(route);
        }
    });
});

test.afterEach(async ({ page }) => {
    await page.evaluate((k) => localStorage.removeItem(k), KEY).catch(() => {});
});
