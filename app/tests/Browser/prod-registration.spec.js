/**
 * End-to-end registration: walks the whole wizard AND posts step 12, so every
 * run creates real distributors on the e2e database (restored by e2e:reset).
 * Step flow mirrors theme-wizard.spec.js.
 *
 * Step 11 uses the company default Arete centre seeded into the e2e base.
 */

import { resetDatabase } from './support/artisan.js';
import { test, expect } from './fixtures.js';

test.use({ world: { name: 'many-partners' } });
test.describe.configure({ timeout: 5 * 60 * 1000 });
test.afterAll(() => resetDatabase());

const [MAILBOX, DOMAIN] = ['reg', 'e2e.test'];

/** Leaves of many-partners with both legs free: p24 and p23. */
const RUNS = [
    { n: 1, placement: '900000025' },
    { n: 2, placement: '900000024' },
    { n: 3, placement: '900000025' },
];

const pathOf = (url) => new URL(url, 'http://x').pathname.replace(/\/$/, '') || '/';
const LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
const PNG_BASE64 =
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
const DOC_FIELDS = ['pan_doc', 'aadhaar_doc', 'aadhaar_back_doc', 'address_proof_front', 'address_proof_back'];

function stepsFor(n) {
    const stamp = (Date.now().toString().slice(-8) + n).slice(-9);
    const pan = [...stamp.slice(0, 5)].map((d) => LETTERS[(Number(d) + 7) % 26]).join('')
        + stamp.slice(0, 4) + LETTERS[Number(stamp[8]) % 26];

    return [
        ['/register/account', '/register/orientation', {
            full_name: `Regtest User ${n}`,
            email: `${MAILBOX}+regtest${n}@${DOMAIN}`,
            phone_e164: `9${stamp}`,
            password: 'Correct-Pergola-Sweep-71',
            password_confirmation: 'Correct-Pergola-Sweep-71',
        }],
        ['/register/orientation', '/register/consent', { quiz_q1: 'A', quiz_q2: 'B', quiz_q3: 'C', confirmed_watched: '1' }],
        ['/register/consent', '/register/identity-documents', {
            consent_tnc: '1', consent_ethics: '1', consent_plan: '1', consent_privacy: '1',
            declared_sound_mind: '1', declared_not_insolvent: '1', declared_no_moral_turpitude: '1',
        }],
        ['/register/identity-documents', '/register/demographics', { pan_number: pan, aadhaar_number: `2${stamp}99`, consent_aadhaar: '1' }],
        ['/register/demographics', '/register/nominee', {
            gender: 'prefer_not_to_say', marital_status: 'prefer_not_to_say', highest_education: 'graduate', mother_tongue: 'Telugu',
        }],
        ['/register/nominee', '/register/kyc/bank', { skip: '1' }],
        ['/register/kyc/bank', '/register/personal', { account_number: '000123456789', ifsc: 'HDFC0001234' }],
        ['/register/personal', '/register/documents', {
            date_of_birth: `${new Date().getFullYear() - 40}-01-01`, state: 'TG', address: '1-2-3 Test Street, Hyderabad',
        }],
    ];
}

/** Posts a step with the page's session + CSRF token; returns where it settled. */
async function post(page, action, fields, files = []) {
    return page.evaluate(async ({ action, fields, files, png }) => {
        const token = document.querySelector('input[name="_token"]')?.value
            ?? document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        let body;
        if (files.length > 0) {
            const bytes = Uint8Array.from(atob(png), (c) => c.charCodeAt(0));
            body = new FormData();
            body.append('_token', token);
            for (const [k, v] of Object.entries(fields)) body.append(k, v);
            for (const f of files) body.append(f, new File([bytes], `${f}.png`, { type: 'image/png' }));
        } else {
            body = new URLSearchParams({ ...fields, _token: token });
        }
        const resp = await fetch(action, { method: 'POST', body, redirect: 'follow' });

        return new URL(resp.url).pathname.replace(/\/$/, '') || '/';
    }, { action, fields, files, png: PNG_BASE64 });
}

for (const run of RUNS) {
    test(`registration ${run.n} completes under ${run.placement}`, async ({ page }) => {
        await page.goto(`/register?sponsor=${run.placement}&placement=${run.placement}`);
        expect(pathOf(page.url()), 'step 1 did not forward — placement full?').toBe('/register/account');

        for (const [from, to, fields] of stepsFor(run.n)) {
            expect(pathOf(page.url()), `${from} bounced`).toBe(from);
            expect(await post(page, from, fields), `posting ${from} was rejected`).toBe(to);
            await page.goto(to);
        }

        expect(await post(page, '/register/documents', {}, DOC_FIELDS), 'documents rejected').toBe('/register/arete-centre');
        await page.goto('/register/arete-centre');
        const centerId = await page.evaluate(() =>
            document.querySelector('select[name="center_id"] option[value]:not([value=""])')?.value
            ?? document.querySelector('input[name="center_id"]')?.value ?? '');
        expect(centerId, 'no Arete centre to pick').not.toBe('');
        expect(await post(page, '/register/arete-centre', { center_id: centerId }), 'Arete step rejected').toBe('/register/complete');

        // Step 12 — the real submit, through the rendered form.
        await page.goto('/register/complete');
        await page.locator('form[action$="/register/complete"]').evaluate((f) => f.requestSubmit());
        await page.waitForLoadState('networkidle');
        expect(pathOf(page.url()), `finalise did not reach the dashboard (title: ${await page.title()})`).toBe('/dashboard');
    });
}
