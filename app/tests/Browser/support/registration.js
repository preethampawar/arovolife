/**
 * The registration wizard driven as a browser does it: every step posted with
 * the page's own session and CSRF token. Shared by prod-registration and
 * joining-rules.
 */

export const [MAILBOX, DOMAIN] = ['reg', 'e2e.test'];

export const pathOf = (url) => new URL(url, 'http://x').pathname.replace(/\/$/, '') || '/';
const LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
const PNG_BASE64 =
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
export const DOC_FIELDS = ['pan_doc', 'aadhaar_doc', 'aadhaar_back_doc', 'address_proof_front', 'address_proof_back'];

export function stepsFor(n, { pan: panOverride } = {}) {
    const stamp = (Date.now().toString().slice(-8) + n).slice(-9);
    const pan = [...stamp.slice(0, 5)].map((d) => LETTERS[(Number(d) + 7) % 26]).join('')
        + stamp.slice(0, 4) + LETTERS[Number(stamp[8]) % 26];
    const panNumber = panOverride ?? pan;

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
        ['/register/identity-documents', '/register/demographics', { pan_number: panNumber, aadhaar_number: `2${stamp}99`, consent_aadhaar: '1' }],
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
export async function post(page, action, fields, files = []) {
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


/**
 * Walks steps 1-11 under $placement and submits step 12. Returns every URL
 * the browser requested on the way, so a spec can prove nothing was bought.
 */
export async function completeRegistration(page, { n, placement, pan } = {}) {
    const requested = [];
    const record = (request) => requested.push(request.url());
    page.on('request', record);

    try {
        await page.goto(`/register?sponsor=${placement}&placement=${placement}`);
        if (pathOf(page.url()) !== '/register/account') {
            throw new Error(`step 1 did not forward under ${placement} — placement full?`);
        }

        for (const [from, to, fields] of stepsFor(n, { pan })) {
            const landed = await post(page, from, fields);
            if (landed !== to) {
                return { stoppedAt: from, landed, requested };
            }
            await page.goto(to);
        }

        if (await post(page, '/register/documents', {}, DOC_FIELDS) !== '/register/arete-centre') {
            return { stoppedAt: '/register/documents', requested };
        }
        await page.goto('/register/arete-centre');
        const centerId = await page.evaluate(() =>
            document.querySelector('select[name="center_id"] option[value]:not([value=""])')?.value
            ?? document.querySelector('input[name="center_id"]')?.value ?? '');
        if (await post(page, '/register/arete-centre', { center_id: centerId }) !== '/register/complete') {
            return { stoppedAt: '/register/arete-centre', requested };
        }

        await page.goto('/register/complete');
        await page.locator('form[action$="/register/complete"]').evaluate((f) => f.requestSubmit());
        await page.waitForLoadState('networkidle');

        return { stoppedAt: null, landed: pathOf(page.url()), requested };
    } finally {
        page.off('request', record);
    }
}
