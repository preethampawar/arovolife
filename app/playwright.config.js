// @ts-check
import { defineConfig, devices } from '@playwright/test';

/**
 * E2E suite — runs against the isolated stack (`make e2e-up`), never dev:
 * http://localhost:8085, database arovolife_e2e. globalSetup aborts unless the
 * e2e container answers `arovolife_e2e`, then restores the base snapshot.
 * Serial on purpose (workers: 1): bonus pools are shared by everyone who
 * qualifies that day, so concurrent money specs would change each other's
 * figures. See docs/testing/e2e-plan.md.
 */
export default defineConfig({
    testDir: './tests/Browser',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: 0,
    workers: 1,
    reporter: 'list',
    globalSetup: './tests/Browser/support/global-setup.js',

    use: {
        baseURL: 'http://localhost:8085',
        // Service workers could fetch past context.route; none are needed.
        serviceWorkers: 'block',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        actionTimeout: 10_000,
        navigationTimeout: 15_000,
        ...devices['Desktop Chrome'],
    },

    projects: [
        {
            name: 'ui',
            testIgnore: ['money/**'],
        },
        {
            name: 'money',
            testDir: './tests/Browser/money',
            dependencies: ['ui'],
        },
    ],
});
