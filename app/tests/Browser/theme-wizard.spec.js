/**
 * Dark mode across the registration wizard, steps 2 to 12.
 *
 * These ten pages are the largest hole the route sweep cannot cover.
 * EnsureRegistrationProgress compares the requested step against the furthest
 * one the session has completed, so theme-sweep.spec.js — which navigates and
 * nothing else — is bounced back to step 3 on every one of them. They are also
 * the pages a joiner sees before they are anyone's customer, and the only ones
 * a distributor never sees again, so nobody would notice them rendering wrong.
 *
 * WHY THIS IS SAFE TO RUN AGAINST A REAL DATABASE
 *
 * The wizard is pure session state. `WizardStateService` keeps every step in
 * the session (the account password included, already hashed), and no user,
 * distributor or placement row exists until `POST /register/complete` calls
 * RegistrationService::finalise(). This spec walks steps 2 to 11 and then GETs
 * step 12 and stops. It never posts step 12. Adding an assertion that posts it
 * would register a distributor on whatever database the run is pointed at.
 *
 * The one side effect is step 10, which uploads five KYC documents: each run
 * leaves five ~100-byte encrypted files under `reg_<session id>/` on the `kyc`
 * disk. That is what an abandoned registration leaves behind anyway, and the
 * prefix is namespaced by session, so runs cannot collide.
 *
 * Everything that has to be unique across runs — email, mobile, PAN — is
 * generated per run, because each is checked against a real table before the
 * step is allowed through, and a fixed value would start failing the first
 * time somebody finished a registration with it.
 */

import AxeBuilder from '@axe-core/playwright';
import { test, expect } from './fixtures.js';
import { WIZARD_ROUTES } from './theme-routes.js';

const KEY = 'arovolife_theme';

/** The wizard is 11 page loads and a file upload; the default 30s is not it. */
test.describe.configure({ timeout: 5 * 60 * 1000 });

const pathOf = (url) => new URL(url, 'http://x').pathname.replace(/\/$/, '') || '/';

/** Same settle sheet the route sweep uses — see theme-sweep.spec.js. */
const SETTLE = `
    *, *::before, *::after { transition: none !important; animation: none !important; }
    [data-reveal] { opacity: 1 !important; transform: none !important; }
`;

/**
 * A sponsor with a free placement leg. Step 1 bounces to Contact Us without
 * one ("placement_full"), which would read as a broken wizard rather than a
 * stale fixture — the same reason the route inventory takes this as an env
 * override.
 */
const SPONSOR = process.env.THEME_WIZARD_ADN ?? '954454971';

const stamp = Date.now().toString().slice(-9);
const LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
const letter = (n) => LETTERS[n % 26];

/** Valid-format PAN that no distributor holds: the check is a pan_hash lookup. */
const PAN = [...stamp.slice(0, 5)].map((d) => letter(Number(d) + 7)).join('')
    + stamp.slice(0, 4)
    + letter(Number(stamp[8]));

/**
 * Field sets that advance each step, and where a successful post lands. The
 * destination is the assertion: Laravel redirects back to the same step on a
 * validation failure, so a post that did not move is a post that was rejected,
 * and it is named here rather than discovered two steps later.
 */
const STEPS = [
    {
        from: '/register/account',
        to: '/register/orientation',
        fields: {
            full_name: 'Theme Sweep',
            email: `theme-sweep-${stamp}@arovolife.test`,
            phone_e164: `9${stamp}`,
            // zxcvbn (StrongPassword) scores this; NotPwned is a k-anonymity
            // lookup that fails open when the network is unreachable.
            password: 'Correct-Pergola-Sweep-71',
            password_confirmation: 'Correct-Pergola-Sweep-71',
        },
    },
    {
        from: '/register/orientation',
        to: '/register/consent',
        fields: { quiz_q1: 'A', quiz_q2: 'B', quiz_q3: 'C', confirmed_watched: '1' },
    },
    {
        from: '/register/consent',
        to: '/register/identity-documents',
        fields: {
            consent_tnc: '1', consent_ethics: '1', consent_plan: '1', consent_privacy: '1',
            declared_sound_mind: '1', declared_not_insolvent: '1', declared_no_moral_turpitude: '1',
        },
    },
    {
        from: '/register/identity-documents',
        to: '/register/demographics',
        fields: { pan_number: PAN, aadhaar_number: `2${stamp}99`, consent_aadhaar: '1' },
    },
    {
        from: '/register/demographics',
        to: '/register/nominee',
        fields: {
            gender: 'prefer_not_to_say',
            marital_status: 'prefer_not_to_say',
            highest_education: 'graduate',
            mother_tongue: 'Telugu',
        },
    },
    // The nominee step offers its own skip, and taking it keeps the spec out of
    // the branch that encrypts an Aadhaar into the session.
    { from: '/register/nominee', to: '/register/kyc/bank', fields: { skip: '1' } },
    {
        // Bank is optional, but filling it is what puts an account number on
        // the step-12 review page, which is the page being scanned.
        from: '/register/kyc/bank',
        to: '/register/personal',
        fields: { account_number: '000123456789', ifsc: 'HDFC0001234' },
    },
    {
        from: '/register/personal',
        to: '/register/documents',
        // Minimum age is per-state and admin-configurable (Maharashtra is 21);
        // 40 years clears every configured floor.
        fields: {
            date_of_birth: `${new Date().getFullYear() - 40}-01-01`,
            state: 'TG',
            address: '1-2-3 Test Street, Hyderabad',
        },
    },
];

/** A 1x1 PNG. The upload rule reads magic bytes, so this has to be a real one. */
const PNG_BASE64 =
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

const DOC_FIELDS = ['pan_doc', 'aadhaar_doc', 'aadhaar_back_doc', 'address_proof_front', 'address_proof_back'];

/**
 * Posts a step as the page itself would: same session cookie, CSRF token read
 * off the rendered form. `files` becomes a multipart body.
 *
 * Returns the path the response settled on after redirects.
 */
async function post(page, action, fields, files = []) {
    return page.evaluate(async ({ action, fields, files, png }) => {
        const token = document.querySelector('input[name="_token"]')?.value
            ?? document.querySelector('meta[name="csrf-token"]')?.content
            ?? '';

        let body;
        if (files.length > 0) {
            const bytes = Uint8Array.from(atob(png), (c) => c.charCodeAt(0));
            body = new FormData();
            body.append('_token', token);
            for (const [k, v] of Object.entries(fields)) {
                body.append(k, v);
            }
            for (const field of files) {
                body.append(field, new File([bytes], `${field}.png`, { type: 'image/png' }));
            }
        } else {
            body = new URLSearchParams({ ...fields, _token: token });
        }

        const resp = await fetch(action, { method: 'POST', body, redirect: 'follow' });

        return new URL(resp.url).pathname.replace(/\/$/, '') || '/';
    }, { action, fields, files, png: PNG_BASE64 });
}

test.describe('dark mode holds across the registration wizard', () => {
    test('every step a joiner walks, 2 through 12', async ({ page }, testInfo) => {
        const scanned = [];
        const violations = [];

        // Step 1 resolves the referral link and forwards to step 2, putting the
        // sponsor and placement in the session. It writes nothing.
        await page.goto(`/register?sponsor=${SPONSOR}&placement=${SPONSOR}`);
        await page.evaluate(([k, v]) => localStorage.setItem(k, v), [KEY, 'dark']);
        await page.reload();
        expect(pathOf(page.url()), 'step 1 did not forward to the account step — check THEME_WIZARD_ADN')
            .toBe('/register/account');

        const scan = async (path) => {
            expect(pathOf(page.url()), `${path} bounced — the step before it did not take`).toBe(path);
            expect(
                await page.evaluate(() => document.documentElement.classList.contains('dark')),
                `${path} lost the stored theme before paint`,
            ).toBe(true);

            await page.addStyleTag({ content: SETTLE });
            const results = await new AxeBuilder({ page }).withRules(['color-contrast']).analyze();

            // A page axe found no text on is indistinguishable from a clean
            // one in the report below, and a wizard step that rendered empty
            // is exactly the failure worth catching.
            const checked = [...results.passes, ...results.violations, ...results.incomplete]
                .reduce((n, r) => n + r.nodes.length, 0);
            expect(checked, `${path} gave axe nothing to score`).toBeGreaterThan(0);

            for (const v of results.violations) {
                for (const node of v.nodes) {
                    const d = node.any?.[0]?.data ?? {};
                    violations.push(`${path}\n    ${d.fgColor} on ${d.bgColor} — ${d.contrastRatio}:1` +
                        ` (${d.fontSize}, weight ${d.fontWeight})\n    ${node.target.join(' ')}`);
                }
            }
            scanned.push(path);
        };

        for (const step of STEPS) {
            await scan(step.from);
            const landed = await post(page, step.from, step.fields);
            expect(landed, `posting ${step.from} was rejected`).toBe(step.to);
            await page.goto(step.to);
        }

        // Step 10 — the only step that writes anything anywhere.
        await scan('/register/documents');
        const afterDocs = await post(page, '/register/documents', {}, DOC_FIELDS);
        expect(afterDocs, 'posting the documents step was rejected').toBe('/register/arete-centre');
        await page.goto('/register/arete-centre');

        // Step 11 — the centre list is seeded, so read the id rather than pin it.
        await scan('/register/arete-centre');
        const centerId = await page.evaluate(() =>
            document.querySelector('select[name="center_id"] option[value]:not([value=""])')?.value
            ?? document.querySelector('input[name="center_id"]')?.value ?? '');
        expect(centerId, 'no selectable Arete centre on the step-11 form').not.toBe('');
        const afterArete = await post(page, '/register/arete-centre', { center_id: centerId });
        expect(afterArete, 'posting the Arete step was rejected').toBe('/register/complete');

        // Step 12 — review. Rendered, scanned, and deliberately not submitted:
        // POST /register/complete is what creates the distributor.
        await page.goto('/register/complete');
        await scan('/register/complete');

        await testInfo.attach('wizard-sweep.json', {
            body: JSON.stringify({ scanned, violations }, null, 2),
            contentType: 'application/json',
        });
        // eslint-disable-next-line no-console
        console.log(`  swept ${scanned.length}/11 wizard steps in dark`);

        // Every step from the inventory, plus the account step the public
        // sweep already covers under its own name.
        expect(scanned.sort()).toEqual([...WIZARD_ROUTES, '/register/account'].sort());
        expect(violations.join('\n\n'), 'contrast failures in dark across the wizard').toBe('');
    });
});

test.afterEach(async ({ page }) => {
    await page.evaluate((k) => localStorage.removeItem(k), KEY).catch(() => {});
});
