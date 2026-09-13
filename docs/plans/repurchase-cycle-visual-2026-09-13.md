# Repurchase cycle — graphical status on the documents page

Date: 2026-09-13

## Goal

A distributor opening **My documents** (`/dashboard/documents`) sees, above the
document list, a highlighted card that answers "where am I in my repurchase
cycle?" at a glance: a ring showing days remaining in the 30-day window, the
window's start and end dates, and progress against the two conditions that
decide the verdict. A distributor who has not yet qualified sees instead the
checklist of what unlocks the obligation, with their progress toward it.

## Non-goals

- No change to how a cycle is opened, evaluated or resolved. This reads
  `RepurchaseCycleService`; it never writes.
- No new engine, no new schema, no scheduled work.
- No chart library. The house pattern is inline SVG
  (`resources/views/dashboard/_cooling-off.blade.php`), which is accessible,
  themable and adds no CDN dependency.
- Not placed on the main dashboard — the ask is specifically the documents page.

## Facts established during discovery

Read from the code, not assumed:

| Fact | Source |
|---|---|
| Qualification gate is **600 personal BV** (`gsbMinBvPaise`), not a title | `RepurchaseCycleService::repurchaseAnchor()` — "the date they first reached the 600-BV personal-purchase minimum (5 Jul 2026 rule; **previously** the 3,000-BV Retailer title)" |
| Before that date there is **no obligation and no cycle** | same method returns null; `currentCycle()` null |
| The obligation is per-rank BV | `requiredBvPaise()` → `rankRepurchaseBvPaise($rank)` or `nonRankedRepurchaseBvPaise()` |
| A cycle has `cycle_start_date`, `due_date`, `required_bv_paise`, `completed_bv_paise`, `wallet_balance_paise`, `wallet_zeroed`, `status`, `failure_reason` | `RepurchaseCycle` |
| Statuses: `active`, `completed`, `suspended`; reasons: `bv_short`, `wallet_nonzero`, `both` | `RepurchaseCycle` constants |
| The whole feature is flag-gated | `RepurchaseEngineFeature` |

> **Open question for the client.** The request named "dealer title" as a
> qualification condition. There is no title gate in the code today — the
> 3,000-BV Retailer title was replaced by the 600-BV minimum on 5 Jul 2026.
> The card therefore shows the **real** gate (600 personal BV). If a title
> gate is wanted back, that is a compensation-plan change, not a UI change.

## Architecture decisions

**A1 — Read-only presenter, built in the controller.** A `RepurchaseCycleCard`
DTO assembled in `KycDocumentSelfServiceController@index` from the existing
services. Alternative — querying inside the Blade — would put money logic in a
view and make it untestable. The DTO is what the test asserts against.

**A2 — Inline SVG ring, matching `_cooling-off.blade.php`.** Same geometry
(`viewBox 0 0 72 72`, `stroke-dasharray`, `role="img"` + `aria-label`), so the
page has one visual language for "time remaining" rather than two.

**A3 — Four states, one component.** `off` (flag down — render nothing),
`not_qualified`, `active`/`completed`, `suspended`. Each state answers a
different question, so each gets its own body rather than one body with
conditionals sprinkled through it.

**A4 — Zero-trace when the flag is off.** Per the project's feature-flag rule,
a flag that is off leaves no placeholder, no heading, nothing.

## Permission matrix

No new route and no new action. The card renders inside a page the viewer has
already been authorised to open, from data already scoped to them.

| Capability | Guest | Distributor (self) | Another distributor | Admin |
|---|---|---|---|---|
| Open `/dashboard/documents` | deny (login) | allow | n/a — no id in the URL | n/a |
| See own repurchase card | deny | allow | **deny** | n/a |
| See another's repurchase figures | deny | **deny** | **deny** | unchanged |

The controller resolves the distributor from `Auth::user()->distributor` only.
No request parameter names a distributor, so there is nothing to inject.

## File changes

| # | Path | New/Modified | Change |
|---|---|---|---|
| 1 | `app/app/Modules/Compensation/Services/DTOs/RepurchaseCycleCard.php` | New | Read-only presenter DTO + `state` constants |
| 2 | `app/app/Modules/Compensation/Services/RepurchaseCycleService.php` | Modified | Add `cardFor(int $distributorId, ?Carbon $today = null): RepurchaseCycleCard` |
| 3 | `app/app/Modules/Identity/Http/Controllers/KycDocumentSelfServiceController.php` | Modified | Build the card, pass `repurchaseCard` to the view |
| 4 | `app/resources/views/dashboard/_repurchase-cycle.blade.php` | New | The card: ring + dates + conditions |
| 5 | `app/resources/views/dashboard/kyc-documents.blade.php` | Modified | `@include` the card above the document list |
| 6 | `app/tests/Modules/Compensation/RepurchaseCycleCardTest.php` | New | One test per state + the scope guarantee |

### Detail — the DTO (#1)

```php
final readonly class RepurchaseCycleCard
{
    public const STATE_NOT_QUALIFIED = 'not_qualified';
    public const STATE_ACTIVE = 'active';
    public const STATE_COMPLETED = 'completed';
    public const STATE_SUSPENDED = 'suspended';

    public function __construct(
        public string $state,
        public ?Carbon $startDate,
        public ?Carbon $endDate,
        public int $daysLeft,          // clamped at 0
        public int $daysTotal,         // window length, for the ring fraction
        public int $requiredBvPaise,
        public int $completedBvPaise,
        public int $walletBalancePaise,
        public bool $walletZeroed,
        public ?string $failureReason,
        public int $personalBvPaise,   // not_qualified: progress toward the gate
        public int $qualifyBvPaise,    // the 600-BV gate, from plan settings
    ) {}

    public function bvFraction(): float;      // completed / required, clamped 0..1
    public function qualifyFraction(): float; // personal / qualify, clamped 0..1
    public function ringFraction(): float;    // daysLeft / daysTotal, clamped 0..1
    public function urgent(): bool;           // daysLeft <= 7 while active
    public function bvMet(): bool;
}
```

Every money value stays paise; the view formats with `@bv` / `IndianNumber`.

### Detail — the ring (#4)

Geometry copied from `_cooling-off.blade.php` so the two read as one family:

```blade
@php $r = 30; $circ = 2 * M_PI * $r; $dash = round($circ * $card->ringFraction(), 2); @endphp
<svg viewBox="0 0 72 72" class="w-20 h-20 shrink-0" role="img"
     aria-label="{{ $card->daysLeft }} of {{ $card->daysTotal }} repurchase days remaining">
    <circle cx="36" cy="36" r="{{ $r }}" fill="none" stroke="#f3f4f6" stroke-width="7"/>
    <circle cx="36" cy="36" r="{{ $r }}" fill="none" stroke="{{ $card->urgent() ? '#b91c1c' : '#166534' }}"
            stroke-width="7" stroke-linecap="round"
            stroke-dasharray="{{ $dash }} {{ round($circ, 2) }}" transform="rotate(-90 36 36)"/>
    <text x="36" y="40" text-anchor="middle" font-size="18" font-weight="700">{{ $card->daysLeft }}</text>
</svg>
```

Light-first utilities only — the global `html.dark` overrides theme it.

Copy follows `.claude/skills/arovolife-ux-writing`: states a fact and a next
step, never a projected earning.

## Test plan

| # | State | Asserts |
|---|---|---|
| 1 | flag off | the card renders nothing at all |
| 2 | not qualified | shows the 600-BV gate and the viewer's progress; no window dates |
| 3 | active | start/end dates, days left, BV progress, wallet condition |
| 4 | active, ≤7 days | urgent styling |
| 5 | completed | reads as met, no countdown pressure |
| 6 | suspended | names the failure reason |
| 7 | scope | the card reflects the signed-in distributor only |

## Acceptance criteria

- [ ] `/dashboard/documents` renders the card for each state.
- [ ] Flag off → no trace.
- [ ] Pint clean, PHPStan level 7 adds no error.
- [ ] `php artisan test` green (run alone — never concurrently against `arovolife_test`).
- [ ] `npm run build`.
