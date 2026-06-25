// @ts-check
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    fullyParallel: false, // Laravel app is stateful — run tests sequentially
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: 1,
    reporter: 'list',

    use: {
        baseURL: process.env.APP_URL ?? 'http://localhost:8084',
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
        // Slow down slightly so transitions don't race
        actionTimeout: 10_000,
        navigationTimeout: 15_000,
    },

    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
