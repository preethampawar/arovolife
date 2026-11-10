// @ts-check
import { execFileSync } from 'node:child_process';

/**
 * Runs artisan inside the e2e container ONLY. The container name is fixed on
 * purpose: the dev container's .env allows destructive compensation tooling on
 * the dev database, so nothing in the Playwright suite may ever be pointed at
 * `arovolife-app`. Every call is preceded (once per process) by `e2e:whoami`,
 * and the suite aborts unless it answers `arovolife_e2e`.
 */
const CONTAINER = 'arovolife-e2e-app';
const EXPECTED_DATABASE = 'arovolife_e2e';

let verified = false;

function exec(args) {
    return execFileSync('docker', ['exec', CONTAINER, 'php', 'artisan', ...args, '--no-interaction'], {
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
        timeout: 600_000,
    });
}

/** @returns {{e2e: boolean, env: string, database: string, server_database: string, clock: string|null}} */
export function whoami() {
    const out = exec(['e2e:whoami']).trim().split('\n').pop() ?? '';
    const info = JSON.parse(out);
    if (!info.e2e || info.database !== EXPECTED_DATABASE || info.server_database !== EXPECTED_DATABASE) {
        throw new Error(`E2E preflight failed — refusing to run: ${out}`);
    }
    return info;
}

export function artisan(...args) {
    if (!verified) {
        whoami();
        verified = true;
    }
    return exec(args);
}

/** The world built since the last reset (module state: workers is 1, so the whole run shares it). */
let currentWorld = null;

/** Restore arovolife_e2e from its template (≈1.5 s) and reset the clock. */
export function resetDatabase({ keepClock = false } = {}) {
    currentWorld = null;
    return artisan('e2e:reset', ...(keepClock ? ['--keep-clock'] : []));
}

/** Flush the e2e cache only (rate-limiter counters). Data, clock and sessions are untouched. */
export function clearThrottles() {
    return artisan('e2e:reset', '--cache-only');
}

/**
 * Build a named scenario (see app/Modules/Shared/Testing/Scenarios/E2eScenarios.php)
 * on the current database and return what it made.
 * @returns {{scenario: string, password: string,
 *   partners: Record<string, {id: number, user_id: number, adn: string, email: string, phone: string, pan_last4: string, customer_id: number|null}>,
 *   orders: Record<string, {id: number, order_no: string, partner: string, bv_paise: number, total_paise: number, paid_at: string}>}}
 */
export function scenario(name) {
    const out = artisan('e2e:scenario', name, '--json').trim().split('\n').pop() ?? '';
    return JSON.parse(out);
}

/**
 * Replay every compensation engine through `asOf` and leave the clock there.
 * @param {string} asOf Asia/Kolkata instant, e.g. "2026-06-05 06:00:00"
 * @param {{settleRepurchase?: string, weeklyBatch?: string, monthlyBatch?: string, approveAs?: string}} [options]
 *   settleRepurchase: months (YYYY-MM, comma-separated) for the two-pass flow;
 *   weeklyBatch: Tuesdays (YYYY-MM-DD, comma-separated) to build a weekly payout batch for;
 *   monthlyBatch: crediting months (YYYY-MM, comma-separated) paid by the 8th's payout close;
 *     asOf must be on or after the 8th of the following month
 *   approveAs: staff e-mail that approves those batches (e.g. "finance@e2e.test")
 */
export function runEngines(asOf, { settleRepurchase, weeklyBatch, monthlyBatch, approveAs } = {}) {
    return artisan(
        'e2e:engines',
        `--as-of=${asOf}`,
        ...(settleRepurchase ? [`--settle-repurchase=${settleRepurchase}`] : []),
        ...(weeklyBatch ? [`--weekly-batch=${weeklyBatch}`] : []),
        ...(monthlyBatch ? [`--monthly-batch=${monthlyBatch}`] : []),
        ...(approveAs ? [`--approve-as=${approveAs}`] : []),
    );
}

/**
 * The named world, built once: reset → scenario → engines (when `asOf` is
 * given). Reused by every later call with the same name until something
 * resets the database, so UI specs share one build instead of one per test.
 */
export function ensureWorld(name, { asOf, ...engineOptions } = {}) {
    const key = JSON.stringify([name, asOf ?? '', engineOptions]);
    if (currentWorld?.key !== key) {
        resetDatabase();
        const built = scenario(name);
        if (asOf) {
            runEngines(asOf, engineOptions);
        }
        currentWorld = { key, ...built };
    }
    return currentWorld;
}

/**
 * Switch a feature flag (class basename, e.g. 'InventoryFeature') on the e2e
 * database. The snapshot pins every flag; a spec that needs one ON wraps its
 * test in withFeature() so later tests in the run keep the pinned set.
 */
export function setFeature(feature, on) {
    return artisan('e2e:feature', feature, on ? 'on' : 'off');
}

/** Run `fn` with `feature` ON, then switch it back OFF. */
export async function withFeature(feature, fn) {
    setFeature(feature, true);
    try {
        return await fn();
    } finally {
        setFeature(feature, false);
    }
}

/** Freeze the platform clock at an Asia/Kolkata instant, e.g. "2026-06-15 23:59:00". */
export function setClock(instant) {
    return artisan('e2e:clock', instant).trim();
}
