/**
 * Developer-only "Delete a partner" (and everyone below them) — browser tests.
 * Plan: docs/plans/partner-subtree-erasure-2026-11-23.md. World `erasure-tree`:
 * root -> target (L) -> tleft (L), tright (R); root -> outsider (R).
 * The "backup is running" refusal is covered by PHP; not driven here (frozen clock).
 */
import { test, expect, loginAsStaff } from './fixtures.js';

test.use({ world: { name: 'erasure-tree' } });

const adn = (w, k) => w.partners[k].adn;

test.describe('developer', () => {
    test.beforeEach(async ({ page, builtWorld }) => {
        await loginAsStaff(page, builtWorld, 'developer');
    });

    test('1 list shows Delete a partner and a row link that prefills the form', async ({ page, builtWorld: w }) => {
        await page.goto('/admin/distributors');
        await expect(page.getByRole('link', { name: 'Delete a partner' })).toBeVisible();
        const row = page.locator('tr', { hasText: adn(w, 'target') }).first();
        await row.getByRole('link', { name: 'Delete', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/distributors\/erase/);
        await expect(page.locator('input[name="adn"]')).toHaveValue(adn(w, 'target'));
    });

    test('2 preview splits Left/Right and omits the outsider', async ({ page, builtWorld: w }) => {
        await page.goto(`/admin/distributors/erase?adn=${adn(w, 'target')}`);
        await page.getByRole('button', { name: 'Review who will be deleted' }).click();
        await expect(page.getByText(adn(w, 'target')).first()).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Left Genos (1)' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Right Genos (1)' })).toBeVisible();
        await expect(page.getByText('Parent APN').first()).toBeVisible();
        const leftRow = page.locator('tr', { hasText: adn(w, 'tleft') });
        await expect(leftRow).toContainText(adn(w, 'target'));
        await expect(page.locator('tr', { hasText: adn(w, 'tright') })).toContainText(adn(w, 'target'));
        await expect(page.locator('body')).not.toContainText(adn(w, 'outsider'));
        await expect(page.locator('body')).not.toContainText(adn(w, 'root'));
    });

    test('3 modal names APN and children; wrong retype errors and deletes nothing', async ({ page, builtWorld: w }) => {
        await page.goto(`/admin/distributors/erase?adn=${adn(w, 'target')}`);
        await page.getByRole('button', { name: 'Review who will be deleted' }).click();
        await page.locator('input[name="confirm_adn"]').fill(adn(w, 'target'));
        await page.getByRole('button', { name: 'Delete permanently' }).click();
        const modal = page.locator('#confirm-modal-ok');
        await expect(modal).toBeVisible();
        const dialog = page.locator('#confirm-modal');
        await expect(dialog).toContainText(adn(w, 'target'));
        await expect(dialog).toContainText(`Left Genos: ${adn(w, 'tleft')}`);
        await expect(dialog).toContainText(`Right Genos: ${adn(w, 'tright')}`);
        await page.screenshot({ path: 'test-results/partner-erasure-modal.png' });
        await page.locator('#confirm-modal-cancel').click();

        // Wrong retype: submit with a different APN.
        await page.locator('input[name="confirm_adn"]').fill('999999999');
        await page.getByRole('button', { name: 'Delete permanently' }).click();
        if (await page.locator('#confirm-modal-ok').isVisible().catch(() => false)) {
            await page.locator('#confirm-modal-ok').click();
        }
        await expect(page.getByText('The APN you typed again does not match.').first()).toBeVisible();

        await page.goto(`/admin/distributors?q=${adn(w, 'target')}`);
        await expect(page.locator('tr', { hasText: adn(w, 'target') }).first()).toBeVisible();
    });

    test('5 unknown APN errors on the form', async ({ page }) => {
        await page.goto('/admin/distributors/erase');
        await page.locator('input[name="adn"]').fill('123456789');
        await page.getByRole('button', { name: 'Review who will be deleted' }).click();
        await expect(page).toHaveURL(/\/admin\/distributors\/erase$/);
        await expect(page.locator('p.text-red-700')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Review who will be deleted' })).toBeVisible();
    });

    test('4 happy path deletes target and children, keeps outsider and root', async ({ page, builtWorld: w }) => {
        await page.goto(`/admin/distributors/erase?adn=${adn(w, 'target')}`);
        await page.getByRole('button', { name: 'Review who will be deleted' }).click();
        await page.locator('input[name="confirm_adn"]').fill(adn(w, 'target'));
        await page.getByRole('button', { name: 'Delete permanently' }).click();
        await page.locator('#confirm-modal-ok').click();
        await expect(page).toHaveURL(/\/admin\/distributors(\?.*)?$/);
        await expect(page.locator('body')).toContainText(
            new RegExp(`APN ${adn(w, 'target')} and 2 partners below it were deleted\\.`));
        await page.screenshot({ path: 'test-results/partner-erasure-done.png' });

        for (const k of ['target', 'tleft', 'tright']) {
            await page.goto(`/admin/distributors?q=${adn(w, k)}`);
            await expect(page.locator('tr', { hasText: adn(w, k) })).toHaveCount(0);
        }
        for (const k of ['outsider', 'root']) {
            await page.goto(`/admin/distributors?q=${adn(w, k)}`);
            await expect(page.locator('tr', { hasText: adn(w, k) }).first()).toBeVisible();
        }
    });
});

test.describe('admin (not developer)', () => {
    test('6 sees no Delete controls and gets 404 on the erase page', async ({ page, builtWorld: w }) => {
        await loginAsStaff(page, w, 'plainadmin');
        await page.goto('/admin/distributors');
        await expect(page.locator('table')).toBeVisible();
        await expect(page.getByRole('link', { name: 'Delete a partner' })).toHaveCount(0);
        await expect(page.getByRole('link', { name: 'Delete', exact: true })).toHaveCount(0);
        const res = await page.goto('/admin/distributors/erase');
        expect(res?.status()).toBe(404);
    });
});
