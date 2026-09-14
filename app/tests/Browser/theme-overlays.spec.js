/**
 * The shared overlays, in dark.
 *
 * theme-sweep.spec.js walks every route, but axe only sees rendered DOM — and
 * a modal, a toast, a dropdown panel and a mobile drawer are all `hidden`
 * until someone opens them. So none of them had ever been scanned in dark, on
 * any page, despite being the components with the widest reach in the app.
 *
 * Each test opens the overlay for real and scans it with axe scoped to the
 * overlay itself, so a pre-existing violation elsewhere on the host page
 * cannot mask — or fake — the overlay's own result.
 *
 * Nothing here submits anything. The confirm modal is opened by a submit that
 * a capture-phase listener has already cancelled, and it is dismissed with
 * Cancel, never Confirm: this suite runs against the dev database and the
 * forms behind these dialogs change real settings.
 */

import AxeBuilder from '@axe-core/playwright';
import { test, expect } from './fixtures.js';

const KEY = 'arovolife_theme';

/** Load `path` with dark already stored, so the page paints dark from the start. */
async function dark(page, path) {
    await page.goto(path);
    await page.evaluate(([k, v]) => localStorage.setItem(k, v), [KEY, 'dark']);
    await page.reload();
    expect(await page.evaluate(() => document.documentElement.classList.contains('dark'))).toBe(true);
}

/** Belt and braces: no form on the page can submit while we poke at it. */
const blockSubmits = (page) =>
    page.evaluate(() => document.addEventListener('submit', (e) => e.preventDefault(), true));

/**
 * axe takes a CSS selector, and Playwright's `:visible` is not one — so the
 * element we actually opened gets tagged for the duration of the scan.
 */
async function scanElement(page, locator) {
    await locator.evaluate((el) => el.setAttribute('data-axe-scope', ''));
    const out = await scan(page, '[data-axe-scope]');
    await locator.evaluate((el) => el.removeAttribute('data-axe-scope'));
    return out;
}

async function scan(page, selector) {
    const results = await new AxeBuilder({ page })
        .include(selector)
        .withRules(['color-contrast'])
        .analyze();

    // A scope that matches nothing produces no violations, which is
    // indistinguishable from a clean overlay. Insist axe actually looked at
    // some text before believing the empty result.
    const checked = [...results.passes, ...results.violations, ...results.incomplete]
        .reduce((n, r) => n + r.nodes.length, 0);
    expect(checked, `axe scored no text inside ${selector} — the scope is wrong, not the colours`)
        .toBeGreaterThan(0);

    return results.violations.flatMap((v) =>
        v.nodes.map((n) => {
            const d = n.any?.[0]?.data ?? {};
            return `${d.fgColor} on ${d.bgColor} — ${d.contrastRatio}:1 — ${n.target.join(' ')}`;
        }));
}

test.afterEach(async ({ page }) => {
    await page.evaluate((k) => localStorage.removeItem(k), KEY).catch(() => {});
});

test.describe('admin overlays', () => {
    test('the editable section and the confirmation modal', async ({ adminPage: page }) => {
        await dark(page, '/admin/settings');
        await blockSubmits(page);

        // The settings groups are collapsed <details>; nothing inside one is
        // clickable, or scannable, until it is open.
        await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));

        const form = page.locator('form[data-editable]').first();
        await form.locator('[data-editable-edit]').click();

        // Unlocked state: inputs enabled, Save and Cancel showing.
        expect(await scan(page, 'form[data-editable]')).toEqual([]);

        // The modal only opens when there is something to confirm: the
        // editable section diffs the fields and submits straight through if
        // nothing changed. Nudge one value — the capture-phase blocker above
        // means it can never reach the server either way.
        const field = form.locator('input:not([type=hidden]), select, textarea').first();
        await field.evaluate((el) => {
            if (el.tagName === 'SELECT') {
                el.selectedIndex = (el.selectedIndex + 1) % el.options.length;
            } else if (el.type === 'number') {
                el.value = String(Number(el.value || 0) + 1);
            } else {
                el.value = `${el.value} `;
            }
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        });

        await form.getByRole('button', { name: /^save$/i }).click();

        const modal = page.locator('#confirm-modal');
        await expect(modal).toBeVisible();
        expect(await scan(page, '#confirm-modal')).toEqual([]);

        await page.locator('#confirm-modal-cancel').click();
        await expect(modal).toBeHidden();
    });

    test('the toast stack, in all three tones', async ({ adminPage: page }) => {
        await dark(page, '/admin/settings');

        await page.evaluate(() => {
            window.showToast('Setting saved.', 'success', 60000);
            window.showToast('Could not reach the payment gateway.', 'error', 60000);
            window.showToast('The daily cut-off runs at 23:59 IST.', 'info', 60000);
        });

        await expect(page.locator('#toastContainer [role="status"]')).toHaveCount(3);
        expect(await scan(page, '#toastContainer')).toEqual([]);
    });
});

test.describe('distributor overlays', () => {
    test('the genealogy node menu, send-message modal and ID-card modal', async ({ distributorPage: page }) => {
        await dark(page, '/tree');

        const triggers = page.locator('[data-node-menu-trigger]');
        const count = await triggers.count();
        expect(count, 'the fixture distributor has no genealogy nodes — set A11Y_ADN to one that does')
            .toBeGreaterThan(0);

        // The root node is the signed-in distributor and has no "send message"
        // entry, so find a node whose panel actually carries one.
        let panel = null;
        for (let i = 0; i < count; i++) {
            await triggers.nth(i).click();
            const candidate = page.locator('[data-node-menu-panel]').locator('visible=true').first();
            if (await candidate.locator('[data-send-message]').count()) {
                panel = candidate;
                break;
            }
            await page.keyboard.press('Escape');
        }
        expect(panel, 'no genealogy node offered a Send message action').not.toBeNull();

        expect(await scanElement(page, panel)).toEqual([]);

        await panel.locator('[data-send-message]').first().click();
        await expect(page.locator('#sendMessageModal')).toBeVisible();
        expect(await scan(page, '#sendMessageModal')).toEqual([]);
        await page.locator('#sendMessageModal [data-modal-close]').first().click();

        await triggers.first().click();
        const details = page.locator('[data-node-menu-panel]').locator('visible=true')
            .locator('[data-open-distributor-details]').first();
        await details.click();
        const idCard = page.locator('#distributorDetailsModal');
        await expect(idCard).toBeVisible();
        // The panel is fetched, so wait for the body to fill before scoring it.
        await expect(idCard.locator('[data-modal-body]')).not.toBeEmpty();
        expect(await scan(page, '#distributorDetailsModal')).toEqual([]);
    });

    test('the mobile navigation drawer at 390px', async ({ distributorPage: page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await dark(page, '/dashboard');

        await page.locator('#distributorNavBtn').click();
        const drawer = page.locator('#distributorNavDrawer');
        await expect(drawer).toBeVisible();

        expect(await scan(page, '#distributorNavDrawer')).toEqual([]);

        await page.locator('#distributorNavCloseBtn').click();
    });
});
