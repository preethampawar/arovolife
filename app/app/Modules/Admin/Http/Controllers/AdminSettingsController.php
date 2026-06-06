<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class AdminSettingsController extends Controller
{
    /**
     * Admin-editable settings registry. Each entry describes how the
     * platform-settings UI should render and validate one key.
     *
     * Setting types:
     *   - 'bool'  : stored as the string 'true' | 'false'
     *   - 'int'   : stored as a decimal integer string; clamped to [min, max]
     *   - 'enum'  : one of the values in `options`
     *   - 'json'  : free-form JSON; validation is custom (see updateStateAgeMinimums)
     *
     * The 'group' field controls grouping in the friendly admin UI.
     *
     * The 'read_only' flag locks the setting against in-UI edits — used for
     * compensation switches and any key whose change would violate one of the
     * eight hard rules (see CLAUDE.md). Read-only keys still appear in the
     * advanced engineer view so they're discoverable, but the friendly UI
     * surfaces them as informational rows only.
     *
     * @return array<string, array{
     *   group: string,
     *   label: string,
     *   description: string,
     *   type: string,
     *   default?: string,
     *   options?: array<int, array{value: string, label: string}>,
     *   min?: int,
     *   max?: int,
     *   read_only?: bool,
     *   read_only_reason?: string,
     * }>
     */
    public static function registry(): array
    {
        return [
            // ── Registration & KYC ─────────────────────────────────────────
            'compliance.state_age_minimums' => [
                'group' => 'registration',
                'label' => 'State-wise minimum age',
                'description' => 'Override the default 18-year minimum registration age for specific states. JSON map of two-letter state code to age (e.g. {"MH":21}).',
                'type' => 'json',
                'default' => '{"MH":21}',
            ],

            // ── Placement (Genos) ──────────────────────────────────────────
            'placement.spillover.enabled' => [
                'group' => 'placement',
                'label' => 'Binary spillover',
                'description' => 'When ON, a joiner invited under a node whose slot is already full is auto-placed into the next open position below it (standard binary spillover, ADR-0007). When OFF, a full placement is rejected and the joiner is asked to use a different placement (ADR-0003). Default OFF. NOTE: enabling this changes how downlines grow and therefore future commission distribution — get PO + Compliance sign-off before turning it on in production.',
                'type' => 'bool',
                'default' => 'false',
            ],

            // ── Commerce — storefront ──────────────────────────────────────
            'commerce.storefront.enabled' => [
                'group' => 'commerce',
                'label' => 'Public storefront',
                'description' => 'Show the public product storefront to visitors. Turn this off to take the catalogue down without removing the app.',
                'type' => 'bool',
                'default' => 'true',
            ],
            'commerce.checkout.enabled' => [
                'group' => 'commerce',
                'label' => 'Storefront checkout',
                'description' => 'Allow customers to complete checkout on the storefront. Turn this off to keep the catalogue browsable but pause new orders.',
                'type' => 'bool',
                'default' => 'true',
            ],
            'commerce.guest_checkout.enabled' => [
                'group' => 'commerce',
                'label' => 'Guest checkout',
                'description' => 'Allow customers to check out without creating an account. Turn off to require sign-in before placing an order.',
                'type' => 'bool',
                'default' => 'true',
            ],
            'commerce.shipping.india_mainland_only' => [
                'group' => 'commerce',
                'label' => 'Mainland India shipping only',
                'description' => 'Restrict orders to mainland Indian addresses. Turn off to accept orders for the Andaman & Nicobar islands, Lakshadweep, etc.',
                'type' => 'bool',
                'default' => 'true',
            ],
            'commerce.orders.daily_cap_per_customer' => [
                'group' => 'commerce',
                'label' => 'Daily order limit per customer',
                'description' => 'Maximum number of orders a single customer can place in 24 hours. Anti-fraud guard; raise carefully.',
                'type' => 'int',
                'min' => 1,
                'max' => 100,
                'default' => '5',
            ],
            'commerce.shipping.fee_rupees' => [
                'group' => 'commerce',
                'label' => 'Shipping fee (₹)',
                'description' => 'Flat shipping charge (in whole rupees) applied to orders below the free-shipping threshold.',
                'type' => 'int',
                'min' => 0,
                'max' => 100000,
                'default' => '60',
            ],
            'commerce.shipping.free_threshold_rupees' => [
                'group' => 'commerce',
                'label' => 'Free-shipping threshold (₹)',
                'description' => 'Cart value (in whole rupees) at or above which shipping is free. Default ₹4000.',
                'type' => 'int',
                'min' => 0,
                'max' => 10000000,
                'default' => '4000',
            ],

            // ── Commerce — attribution ─────────────────────────────────────
            'commerce.attribution.window_days' => [
                'group' => 'attribution',
                'label' => 'Referral attribution window (days)',
                'description' => 'How many days a referral link remains attached to a visitor for commission attribution. Default 30.',
                'type' => 'int',
                'min' => 1,
                'max' => 365,
                'default' => '30',
            ],
            'commerce.attribution.logged_in_overrides_ref' => [
                'group' => 'attribution',
                'label' => 'Logged-in distributor beats referral cookie',
                'description' => 'When a logged-in distributor checks out, their own ADN is attributed even if a different referral cookie is set.',
                'type' => 'bool',
                'default' => 'true',
            ],

            // ── Cooling-off ────────────────────────────────────────────────
            'commerce.cooling_off.days' => [
                'group' => 'cooling_off',
                'label' => 'Cooling-off period (days)',
                'description' => 'How long after delivery a customer can cancel for a full refund. Statutory floor is 30 days; the law does not permit a shorter window.',
                'type' => 'int',
                // 30 is the statutory minimum (DSR 2021 / T&C §4). UI cannot
                // accept values below 30; the controller revalidates.
                'min' => 30,
                'max' => 365,
                'default' => '30',
            ],

            // ── Self-purchase ──────────────────────────────────────────────
            'commerce.self_purchase.earns_bv' => [
                'group' => 'self_purchase',
                'label' => 'Self-purchase earns BV',
                'description' => "When ON, a distributor's own product purchases count toward their Business Volume (BV).",
                'type' => 'bool',
                'default' => 'true',
            ],
            'commerce.self_purchase.earns_retail_margin' => [
                'group' => 'self_purchase',
                'label' => 'Self-purchase earns retail margin',
                'description' => "When ON, a distributor's own product purchases earn the retail margin component. Default OFF — self-purchase typically only contributes BV, not retail margin.",
                'type' => 'bool',
                'default' => 'false',
            ],

            // ── Compensation switches (read-only in UI) ────────────────────
            'compensation.accrual.enabled' => [
                'group' => 'compensation',
                'label' => 'Compensation: accrual engine',
                'description' => 'Master switch for accruing commissions on product sales. Phase 4+ only; do not enable from this UI.',
                'type' => 'bool',
                'default' => 'false',
                'read_only' => true,
                'read_only_reason' => 'Compensation accrual is gated by phase-rollout review (see CLAUDE.md hard rule 2). Toggle this via a controlled migration or admin tinker session only.',
            ],
            'compensation.unlock.enabled' => [
                'group' => 'compensation',
                'label' => 'Compensation: unlock engine',
                'description' => 'Master switch for unlocking matured commissions for payout. Phase 4+ only.',
                'type' => 'bool',
                'default' => 'false',
                'read_only' => true,
                'read_only_reason' => 'Compensation unlock is gated by phase-rollout review (see CLAUDE.md hard rule 2).',
            ],
            'compensation.payout.enabled' => [
                'group' => 'compensation',
                'label' => 'Compensation: payout engine',
                'description' => 'Master switch for actually paying out matured commissions. Phase 4+ only.',
                'type' => 'bool',
                'default' => 'false',
                'read_only' => true,
                'read_only_reason' => 'Compensation payout is gated by phase-rollout review (see CLAUDE.md hard rule 2).',
            ],

            // ── Compliance / operations ────────────────────────────────────
            'compliance.crawler.enabled' => [
                'group' => 'advanced',
                'label' => 'Compliance crawler',
                'description' => 'When ON, the automated crawler scans distributor public posts for compliance violations. Default OFF — manual review for Phase 2.',
                'type' => 'bool',
                'default' => 'false',
            ],

            // ── Notifications ──────────────────────────────────────────────
            'notifications.email_on_status_change' => [
                'group' => 'notifications',
                'label' => 'Email on every order status change',
                'description' => 'When ON, the buyer is emailed each time their order moves status (paid, shipped, delivered, …). The order-received email is always sent regardless of this toggle.',
                'type' => 'bool',
                'default' => 'true',
            ],

            // ── Payments ───────────────────────────────────────────────────
            'payments.cod.enabled' => [
                'group' => 'payments',
                'label' => 'Cash on Delivery',
                'description' => 'Offer Cash on Delivery at checkout. Default OFF — when off, only online payment is available and the invoice is generated after payment.',
                'type' => 'bool',
                'default' => 'false',
            ],
            'payments.gateway.razorpay.enabled' => [
                'group' => 'payments',
                'label' => 'Payments: Razorpay gateway',
                'description' => 'Route checkouts through Razorpay. Requires keys in the environment; do not enable without the finance team.',
                'type' => 'bool',
                'default' => 'false',
            ],
            'payments.gateway.stub.enabled' => [
                'group' => 'payments',
                'label' => 'Payments: stub gateway (dev)',
                'description' => 'Accept payments via the in-process stub. Useful for local development; never enable on production.',
                'type' => 'bool',
                'default' => 'true',
            ],
        ];
    }

    /**
     * Friendly labels for the section grouping.
     *
     * @return array<string, array{label: string, description: string}>
     */
    public static function groups(): array
    {
        return [
            'registration' => [
                'label' => 'Registration & KYC',
                'description' => 'Rules that govern who can register and on what terms.',
            ],
            'placement' => [
                'label' => 'Placement (Genos)',
                'description' => 'How new joiners are positioned in the binary placement tree.',
            ],
            'commerce' => [
                'label' => 'Commerce',
                'description' => 'Storefront, checkout, shipping and order-flow controls.',
            ],
            'attribution' => [
                'label' => 'Referral attribution',
                'description' => 'How sales get tied to a sponsor for commission credit.',
            ],
            'cooling_off' => [
                'label' => 'Cooling-off',
                'description' => 'Statutory 30-day cancel-for-refund window. Cannot be set below 30.',
            ],
            'self_purchase' => [
                'label' => 'Self-purchase',
                'description' => 'How a distributor\'s own purchases contribute to their volume and earnings.',
            ],
            'compensation' => [
                'label' => 'Compensation engine',
                'description' => 'Master switches for the Phase 4+ commission engine. Read-only from this UI.',
            ],
            'payments' => [
                'label' => 'Payments',
                'description' => 'Payment methods offered at checkout and the gateways that process them.',
            ],
            'notifications' => [
                'label' => 'Notifications',
                'description' => 'Transactional emails (and, later, SMS) sent to buyers about their orders.',
            ],
            'advanced' => [
                'label' => 'Other / Advanced',
                'description' => 'Lower-traffic operations switches.',
            ],
        ];
    }

    public function index(): View
    {
        $rows = DB::table('settings')->orderBy('key')->get()->keyBy('key');
        $registry = self::registry();

        // Build a grouped view-model. Each group only renders if it has at
        // least one matching setting, so removing a key from the registry
        // doesn't leave behind an empty section header.
        $grouped = [];
        foreach (self::groups() as $groupKey => $groupMeta) {
            $grouped[$groupKey] = [
                'meta' => $groupMeta,
                'items' => [],
            ];
        }

        foreach ($registry as $key => $meta) {
            $row = $rows[$key] ?? null;
            $rawValue = $row->value ?? ($meta['default'] ?? '');
            $grouped[$meta['group']]['items'][] = [
                'key' => $key,
                'meta' => $meta,
                'value' => $rawValue,
                'version' => (int) ($row->version ?? 0),
            ];
        }

        // Drop empty groups so the page doesn't show stale headers.
        $grouped = array_filter($grouped, fn (array $g): bool => $g['items'] !== []);

        return view('admin.settings.index', [
            'settings' => $rows,           // for the legacy advanced/engineer view
            'grouped' => $grouped,         // for the friendly view
            'registry' => $registry,
        ]);
    }

    /**
     * Generic per-setting update endpoint used by the friendly UI. Each
     * setting card posts {value: ...} here; we validate against the
     * registry entry, write the value + bump the version, and append an
     * audit-log row.
     */
    public function update(Request $request, string $key): RedirectResponse
    {
        $registry = self::registry();
        abort_unless(isset($registry[$key]), 404);

        $meta = $registry[$key];

        if (! empty($meta['read_only'])) {
            // Read-only settings must not be writable via the UI even if a
            // hand-crafted POST sneaks through. This protects compensation
            // switches per CLAUDE.md hard rule 2.
            abort(403, 'This setting is read-only from the admin UI.');
        }

        // JSON-typed settings have a dedicated handler (with custom
        // validation, e.g. state code regex + age-range bounds).
        if ($meta['type'] === 'json') {
            if ($key === 'compliance.state_age_minimums') {
                return $this->updateStateAgeMinimums($request);
            }
            abort(400, 'No handler for JSON-typed setting: '.$key);
        }

        $error = null;
        $value = $this->normalizeIncomingValue($request, $meta, $error);
        if ($error !== null) {
            return redirect()->route('admin.settings')
                ->withErrors(['value' => $error])
                ->with('saved_key', $key);
        }

        $this->persistSetting($key, $value, $request);

        return redirect()->route('admin.settings')
            ->with('status', "Updated: {$meta['label']}.")
            ->with('saved_key', $key);
    }

    /**
     * Normalize the incoming `value` field according to the registry entry.
     * Returns the canonical string to persist. Out-of-range / type errors
     * are reported via the by-ref $error so the caller can redirect back
     * with errors instead of throwing through abort().
     *
     * @param  array{type: string, options?: array<int, array{value: string, label: string}>, min?: int, max?: int, label: string}  $meta
     */
    private function normalizeIncomingValue(Request $request, array $meta, ?string &$error = null): string
    {
        switch ($meta['type']) {
            case 'bool':
                // Accept HTML checkbox semantics ("on" / missing) AND
                // explicit "true"/"false" strings from the toggle button.
                $raw = $request->input('value');
                if ($raw === null) {
                    return 'false';
                }
                $rawStr = is_string($raw) ? strtolower($raw) : (string) $raw;
                $truthy = in_array($rawStr, ['1', 'true', 'on', 'yes'], true);

                return $truthy ? 'true' : 'false';

            case 'int':
                $raw = $request->input('value');
                if (! is_numeric($raw) || ((int) $raw) != $raw) { // phpcs:ignore SlevomatCodingStandard.Operators.DisallowEqualOperators
                    $error = "{$meta['label']} must be a whole number.";

                    return '';
                }
                $n = (int) $raw;
                $min = $meta['min'] ?? PHP_INT_MIN;
                $max = $meta['max'] ?? PHP_INT_MAX;
                if ($n < $min || $n > $max) {
                    $error = "{$meta['label']} must be between {$min} and {$max}.";

                    return '';
                }

                return (string) $n;

            case 'enum':
                $raw = $request->input('value');
                $allowed = array_map(fn ($o): string => $o['value'], $meta['options'] ?? []);
                if (! is_string($raw) || ! in_array($raw, $allowed, true)) {
                    $error = "{$meta['label']}: invalid choice.";

                    return '';
                }

                return $raw;

            default:
                $error = 'Unsupported setting type: '.$meta['type'];

                return '';
        }
    }

    private function persistSetting(string $key, string $value, Request $request): void
    {
        // Read-update-or-insert. We previously used upsert(..., version =>
        // DB::raw('version + 1')), but the raw expression is non-portable
        // across drivers (MySQL evaluates `version + 1` against the
        // existing row in the UPDATE branch; SQLite's ON CONFLICT DO
        // UPDATE references the excluded.version literal and explodes).
        // A small fetch + branch is portable and the table is single-row
        // per key so there's no hot-path concern.
        $existing = DB::table('settings')->where('key', $key)->first();
        $old = $existing->value ?? null;

        if ($existing === null) {
            DB::table('settings')->insert([
                'key' => $key,
                'value' => $value,
                'version' => 1,
                'updated_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('settings')
                ->where('key', $key)
                ->update([
                    'value' => $value,
                    'version' => ((int) ($existing->version ?? 0)) + 1,
                    'updated_by' => auth()->id(),
                    'updated_at' => now(),
                ]);
        }

        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => 'admin.settings.changed',
            'subject_type' => 'settings',
            'subject_id' => null,
            'details' => [
                'key' => $key,
                'before' => $old,
                'after' => $value,
            ],
            'ip' => $request->ip(),
        ]);
    }

    public function updateStateAgeMinimums(Request $request): RedirectResponse
    {
        // The form posts a JSON string in 'state_age_minimums' (textarea).
        // We parse + revalidate every value as a sane integer (16..30) so a
        // typo can't accidentally allow children or block the entire country.
        //
        // Note: the new friendly UI posts to /admin/settings/{key} with
        // 'value', so we accept either field name to keep the legacy
        // endpoint working.
        $payload = $request->input('state_age_minimums', $request->input('value'));
        $validated = validator(
            ['state_age_minimums' => $payload],
            ['state_age_minimums' => ['required', 'string', 'max:2048']]
        )->validate();

        $decoded = json_decode($validated['state_age_minimums'], true);
        if (! is_array($decoded)) {
            return back()->withInput()->withErrors([
                'state_age_minimums' => 'Must be a valid JSON object mapping state codes to ages.',
            ]);
        }

        foreach ($decoded as $stateCode => $age) {
            if (! is_string($stateCode) || ! preg_match('/^[A-Z]{2}$/', $stateCode)) {
                return back()->withInput()->withErrors([
                    'state_age_minimums' => "Invalid state code: '{$stateCode}'. Use two-letter uppercase codes (e.g. MH).",
                ]);
            }
            if (! is_int($age) || $age < 16 || $age > 30) {
                return back()->withInput()->withErrors([
                    'state_age_minimums' => "Minimum age for {$stateCode} must be an integer between 16 and 30 (got: ".json_encode($age).').',
                ]);
            }
        }

        // Canonical key order so audit-log diffs and downstream string
        // comparisons don't see false changes when admins re-save the same
        // logical map.
        ksort($decoded);
        $canonical = json_encode($decoded, JSON_UNESCAPED_UNICODE);
        if ($canonical === false) {
            return back()->withInput()->withErrors([
                'state_age_minimums' => 'Could not re-encode the JSON map.',
            ]);
        }

        $existing = DB::table('settings')->where('key', 'compliance.state_age_minimums')->first();
        $old = $existing->value ?? null;

        if ($existing === null) {
            DB::table('settings')->insert([
                'key' => 'compliance.state_age_minimums',
                'value' => $canonical,
                'version' => 1,
                'updated_by' => auth()->id(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('settings')
                ->where('key', 'compliance.state_age_minimums')
                ->update([
                    'value' => $canonical,
                    'version' => ((int) ($existing->version ?? 0)) + 1,
                    'updated_by' => auth()->id(),
                    'updated_at' => now(),
                ]);
        }

        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => 'admin.settings.state_age_minimums.changed',
            'subject_type' => 'settings',
            'subject_id' => null,
            'details' => ['before' => $old, 'after' => $canonical],
            'ip' => $request->ip(),
        ]);

        return redirect()->route('admin.settings')
            ->with('status', 'State-age minimums updated. New registrations are validated against the new rule.')
            ->with('saved_key', 'compliance.state_age_minimums');
    }
}
