# Client updates — 4 Nov 2026 ("Development Updates & New Requirements")

**Status:** requirements APPROVED by the user on 2026-11-10. **Nothing built yet.**
**Source:** the client's document dated Wed 4 Nov 2026, 2:40 pm (file "Arovolife Is Our New Life…06-11-2026"). The user confirmed it is the latest source of truth and supersedes earlier figures.
**Shared requirements doc:** https://claude.ai/artifact/VbiPAY5Jc3KCjArB6aaYj6

> Plan changes take effect only after `/p/compensation` is updated in the admin portal (R-125, developer approval) and per the plan-start rules in R-123. Change plan parameters only at a period boundary. Go-live working assumption: 1 Jan 2027.

---

## 1. Revenue split (authoritative)

| Bonus | Setting | Now | New |
|---|---|---|---|
| Genos Sales Bonus (GSB) | `comp.gsb.pool_rate_bp` | 45% | **42%** |
| Growth Booster Bonus (GBB) | `comp.gbb.pool_rate_bp` | 4% | **6%** |
| Fortune Bonus (FB) | `comp.fortune.pool_rate_bp` | 5% | 5% |
| Mentorship Bonus (MB) | `comp.msb.pool_rate_bp` | 3% | **4%** |
| Rank Bonus (RB) | `comp.rank.envelope_bp` | 20% | 20% |
| Lifetime Awards & Rewards | `comp.awards.fund_rate_bp` | 20% | 20% |
| Arete Education Center (AEC) Bonus | `comp.adc.rate_bp` | 3% | 3% |
| **Total** | | 100% | **100%** |

The client's "old" column (GSB 46%, sums to 101%) is ignored. **Monthly income cap** `comp.monthly_income_cap_paise`: ₹50 lakh → **₹1 crore**.
**Q1:** keep the GBB ₹240 and MB ₹120 point-value caps.

## 2. Renames (user-facing text only)

- Distributor → **Partner** (~170 view hits).
- ADN → **APN** (Arovolife Partner Number), all labels incl. CSV/XLSX headers (~226 hits). Code, DB columns and routes keep `adn` (**Q3**).
- Arete Development Center (ADC) → **Arete Education Center (AEC)** (~41 hits).
- **Q2:** GSB personal-purchase titles 4–7 → Partner / Regional Partner / National Partner / Global Partner. Screens must keep titles visually distinct from rank names.
- Also update: legal agreements (DSA, Code of Ethics, plan page), DLT-registered SMS templates (re-registration needed), emails.

## 3. GSB engine

- **Lifetime weaker-side top-up.** Once a partner has qualified any GSB slab, their personal-purchase BV is added to their weaker side every day for life. Replaces the current conditional top-up in `GsbCutoffService` (fires only when a side reaches the slab-1 threshold).
  - **Q4a:** personal BV built up before the first qualification is credited on the qualifying day, before that day's match.
  - **Q4b:** applies in every status except terminated or cancelled in cooling-off.
  - **Q4c:** drop the Rank R1/R2 weaker-leg top-up (15K/30K) in `RankQualificationService` — the GSB top-up already lands in `group_bv_daily`, which rank qualification sums (double count otherwise).
- **Two-stage pricing (Q5).** Stage 1 = slabs 1–3, pro-rated from the 42% daily pool at ≤ ₹250/point. Stage 2 = slabs 4–7, pro-rated from the remainder at ≤ ₹250/point. Total never exceeds the pool (retires today's always-paid-in-full slabs 1–2 overshoot). `GsbDailyPoolService::FIRST_VARIABLE_SLAB` becomes an admin setting (stage boundary).
- GSB itself gets no direct-sponsee condition.

## 4. Rank R1 Silver / R2 Pearl (Q6)

R1 **and** R2 each need ≥ 1 direct sponsee on each side (anywhere in that side) whose **lifetime personal BV ≥ 3,000** (signed; refunds reduce it). Added to `RankTiersSeeder`/rank conditions; R3–R9 unchanged.

## 5. AEC change request (Q7)

Replaces the instant OTP centre change on the profile page (`ProfileController`, OTP purpose `arete_centre_change`).
- Partner submits a request from the dashboard; admin approves or rejects.
- Target: any active approved centre.
- One approved change per 12 months (admin setting).
- Effective from the approval date (`AreteCenterMember.effective_from/to`); AEC credit per purchase follows the purchase date.
- Audit-logged.

## 6. UI items

- **Logout:** a visible button in the top bar (`partials/public-topnav.blade.php`), not only in the avatar menu.
- **Genos zoom (Q8):** all devices — pinch, trackpad, slider, bigger buttons, fit to screen, centre on me (`tree/_content.blade.php`).
- **Direct Referral count (Q9):** on Profile Stats — current sponsees excl. cooling-off-cancelled and terminated, plus Left/Right split, through the team-stats single source (`DistributorIdCardStats`).
- **Downloads library (Q10):** signed-in partners only; admin permission `downloads.manage`; admin-defined categories; audit-logged; files in a private S3 bucket; video limit 200 MB (setting); play in page + download.

## 7. Build order

1. Settings: split + ₹1 crore cap
2. Logout button + Direct Referral count
3. Rename sweep
4. GSB two-stage pricing, then lifetime top-up
5. Rank R1/R2 condition + drop rank top-up
6. AEC change request
7. Genos zoom, then Downloads

## 8. Pending (not decided)

- **Q11 Free International Trip contest** (100 days, 3L/3L cumulative matched BV, unlimited depth, counting stops on qualification, public scrolling banner with serial no., APN, name, district, state). Open: set-up and start date, refunds after qualifying, eligibility (KYC/active), banner consent, winner cap. See R-127.
- **Q12 Product Partnership Program** (₹30k entry, first 100, 60-day cooling-off, ₹3k/month wallet credit for 10 months regardless of activity, quarterly dividends to ₹75k). Needs a legal/CA opinion before any build. See R-126.
