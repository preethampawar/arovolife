/**
 * End-to-end registration: walks the whole wizard AND posts step 12, so every
 * run creates real distributors on the e2e database (restored by e2e:reset).
 * Step flow mirrors theme-wizard.spec.js.
 *
 * Step 11 uses the company default Arete centre seeded into the e2e base.
 */

import { resetDatabase } from './support/artisan.js';
import { completeRegistration } from './support/registration.js';
import { test, expect } from './fixtures.js';

test.use({ world: { name: 'many-partners' } });
test.describe.configure({ timeout: 5 * 60 * 1000 });
test.afterAll(() => resetDatabase());

/** The last two depth-5 reserved company accounts: scenarios never place under them. */
const RUNS = [
    { n: 1, placement: '576780921' },
    { n: 2, placement: '722492701' },
    { n: 3, placement: '576780921' },
];

for (const run of RUNS) {
    test(`registration ${run.n} completes under ${run.placement}`, async ({ page }) => {
        const result = await completeRegistration(page, run);

        expect(result.stoppedAt, `the wizard rejected ${result.stoppedAt}`).toBeNull();
        expect(result.landed, `finalise did not reach the dashboard (title: ${await page.title()})`).toBe('/dashboard');
    });
}
