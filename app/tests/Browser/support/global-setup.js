// @ts-check
import { mkdirSync } from 'node:fs';
import { dirname } from 'node:path';
import { chromium } from '@playwright/test';
import { resetDatabase, whoami } from './artisan.js';
import { ADMIN_STATE, E2E_ORIGIN, seal } from './e2e-test.js';

export const ADMIN_LOGIN = { login: 'admin@arovolife.test', password: 'admin12345' };

/**
 * Preflight (wrong container or database → abort), a clean base state, then
 * one admin login saved as storageState. Sessions live in a Redis database
 * that `e2e:reset` never touches, and the admin user is part of the base
 * snapshot, so the saved login survives every reset in the run — and the
 * login throttle is never hit by logging in once per test.
 */
export default async function globalSetup() {
    const info = whoami();
    console.log(`[e2e] ${info.database} on ${info.env}, clock ${info.clock}`);
    resetDatabase();

    const browser = await chromium.launch();
    try {
        const context = await browser.newContext({ baseURL: E2E_ORIGIN, serviceWorkers: 'block' });
        await seal(context);
        const page = await context.newPage();
        await page.goto('/login');
        await page.locator('input[name="login"]').fill(ADMIN_LOGIN.login);
        await page.locator('input[name="password"]').fill(ADMIN_LOGIN.password);
        await page.locator('input[name="password"]').evaluate((input) => input.form?.submit());
        await page.waitForURL((url) => !url.pathname.startsWith('/login'));
        await page.goto('/admin');
        if (!new URL(page.url()).pathname.startsWith('/admin')) {
            throw new Error(`Admin login failed — landed on ${page.url()}`);
        }
        mkdirSync(dirname(ADMIN_STATE), { recursive: true });
        await context.storageState({ path: ADMIN_STATE });
    } finally {
        await browser.close();
    }
}
