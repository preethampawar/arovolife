// @ts-check
import { test as base, expect } from '@playwright/test';

/** The only origin a spec may talk to: the e2e stack. Dev (:8084) and the internet are blocked. */
export const E2E_ORIGIN = 'http://localhost:8085';

/** Where globalSetup saves the admin login (git-ignored). */
export const ADMIN_STATE = new URL('../.auth/admin.json', import.meta.url).pathname;

/**
 * Abort every request and WebSocket from `context` to any other origin —
 * Razorpay checkout JS, Google Analytics, fonts, and the dev app on :8084 —
 * so a spec can never reach a real service or dev data. Every context the
 * suite opens must go through this.
 * @param {import('@playwright/test').BrowserContext} context
 */
export async function seal(context) {
    await context.route(
        (url) => url.origin !== E2E_ORIGIN,
        (route) => route.abort('blockedbyclient'),
    );
    await context.routeWebSocket(
        (url) => url.origin.replace(/^ws/, 'http') !== E2E_ORIGIN,
        (ws) => ws.close(),
    );
}

/** Base test for the whole suite (fixtures.js extends it): the default context is sealed automatically. */
export const test = base.extend({
    sealContext: [
        async ({ context }, use) => {
            await seal(context);
            await use();
        },
        { auto: true },
    ],
});

export { expect };
