# Compensation Plan — Clarifications with KP

**Source plan:** *Arovolife Is Our New Life* (26-06-2026, 3.30pm version)
**Round-1 questions sent:** 2026-06-26 · **KP's answers received:** 2026-06-27 (Google Doc Q&A)
**Status:** Round 1 fully answered. Round 2 + 3 open gaps below.

---

## Round 1 — ANSWERED by KP (2026-06-27)

### GSB

**Q1 — Can the 1st slab (15,000/15,000 → ₹1,800) repeat?**
**KP:** Yes. A distributor can avail all seven GSB slabs multiple times for life; you can move between Slab 1 and Slab 7 in either direction any number of times. Slab 1 is calculated on a **lifetime** basis until each 15,000 match completes; Slabs 2–7 are calculated **daily**, closing 11:59 PM.
**Our state:** ✅ matches the engine. No change.

**Q2 — Does the 4,50,000 BV power-side cap apply to Slab 1 too?**
**KP:** Power carry-forward applies to all seven slabs, max **4,50,000 BV**.
**Our state:** ✅ matches (`gsb_power_cf_cap_paise = 45,000,000`). No change.

### Mentorship Bonus

**Q3 — 10% on gross or net?**
**KP:** On **gross** income. ₹1,800 GSB → ₹180 mentorship.
**Our state:** ✅ MB reads `gross_gsb_paise`. No change.

**Q4 — How do the step-down brackets work?**
**KP:** Tax-bracket style, per ₹30,000 of the sponsee's **cumulative** GSB:
₹0–30k = 10%, 30,001–60k = 9%, 60,001–90k = 8%, 90,001–1,20,000 = 7%, 1,20,001–1,50,000 = 6%, 1,50,001–1,80,000 = 5%, 1,80,001–2,10,000 = 4%, 2,10,001–2,40,000 = 3%, 2,40,001–2,70,000 = 2%, **2,70,001 onwards = 1% for life**. (Example: Ravi earned ₹45,000 → 10% on first 30k + 9% on next 15k = ₹4,350.)
**Deviation → ACTION:** our engine applies **one rate per GSB event** (by prior cumulative), not a true per-slice split, and has an off-by-one at the exact ₹2,70,000 boundary. **Refactor MB to split a single income across brackets.**

### General

**Q5 — Admin charge + TDS, and on which incomes?**
**KP:** 3% admin charge (max ₹30,000) **and** 5% TDS apply to **all seven** bonuses: GSB, Mentorship, Growth Booster, Rank, Fortune, **Lifetime Awards & Rewards**, **and Arete Development Center**.
**Deviation → ACTION:** our ADC service is admin-charge **exempt**, and the old skill exempted ADC + Awards + Fortune. **Add the admin charge to ADC; make the admin-charge scope a per-bonus admin toggle (default all on).**

**Q6 — ₹50 lakh monthly cap mechanics?**
**KP:** Cap total of the five incomes (GSB + MB + GBB + Rank + Fortune) at ₹50 lakh/distributor/month. Pay ₹50 lakh, **forfeit** the excess (no carryover). When the cap is hit, **rank income is adjusted** to fit.
**Our state:** not built. Parked (payout orchestration, later phase). **Open follow-up:** order to cut *after* rank income (Round 2, Q6).

**Q7 — Missed repurchase: grace + consequence?**
**KP:** Monthly redeem BV — non-ranked **600**; **R1 1,000 / R2 1,100 / R3 1,200 / R4 1,300 / R5 1,400 / R6 1,600 / … / R9 2,300** — and bring the repurchase wallet to zero by the personal monthly date. Miss it → **7 extra days**. Still unmet → Left/Right Genos BV not credited; **no GSB, Fortune, or Growth Booster**. **Mentorship still paid.** Rank: that month's BV aggregated without deduction.
**Deviation → ACTION:** ranked repurchase BV is **graduated per rank**, not flat 1,000. **GAP: R7 and R8 values missing.** Parked pending those.

**Q8 — Cancelled order: clawback?**
**KP:** **No clawback** of already-paid bonus (keep the ₹400). Just reverse the **BV** — deduct it from **future** accumulated BV on the **same leg**. If personal-purchase BV was added to the weaker side then cancelled, deduct the equivalent from that side's downline BV.
**+ Follow-up answered 2026-06-27:** if the leg can't absorb it, the leg **carries a negative BV** until future BV covers it.
**Our state:** spec for the refund pipeline (Phase 2). Parked. **Open follow-up:** does the reversal apply up the whole upline chain? (Round 2, Q5).

**Q9 — Lifetime Awards: actual rewards per rank?**
**KP:** Full per-rank catalog with budgets (itemized worths reconcile to each budget):
- **R1 Silver — ₹15,000:** ₹15 L accident insurance.
- **R2 Pearl — ₹30,000:** ₹30 L term insurance.
- **R3 Emerald — ₹90,000:** ₹15 L health insurance (2+2) ₹25k; foreign trip (3N/4D) ₹50k; gold ₹15k.
- **R4 Gold — ₹3,65,000:** 4 foreign tickets ₹2 L; Samsung Tab ₹30k; gold ₹1,35,000.
- **R5 Diamond — ₹10,00,000:** 4 foreign tickets ₹2 L; car down-payment ₹5 L; iPhone ₹1 L; gold ₹2 L.
- **R6 Blue Diamond — ₹30,00,000:** 4 foreign tickets (6N/7D) ₹4 L; car DP ₹15 L; laptop DP ₹1,50,000; iPhone ₹1,50,000; preloaded card ₹1 L; gold ₹7 L.
- **R7 Royal Diamond — ₹90,00,000:** 4 foreign tickets (6N/7D) ₹4 L; house DP ₹64 L; Bullet DP ₹1,50,000; 2 iPhones ₹3 L; preloaded card ₹2 L; gold ₹8 L; silver ₹7,50,000.
- **R8 Crown Diamond — ₹1,40,00,000:** 4 foreign tickets (10N/11D) ₹10 L; house DP ₹75 L; luxury car DP ₹34 L; preloaded card ₹3 L; office rent ₹50k; gold ₹9 L; silver ₹8,50,000.
- **R9 Elite Diamond — ₹2,25,00,000:** 4 foreign tickets (10N/11D) ₹10 L; villa DP ₹1,35,00,000; luxury car DP ₹50 L; driver salary ₹50k; office rent ₹1 L; PA salary ₹50k; preloaded card ₹5 L; gold ₹12 L; silver ₹11 L.
**Our state:** Phase 5. Parked. **Open follow-ups:** once-per-rank vs per-re-proof, and is 18.5% a hard budget cap? (Round 2, Q8).

**Q10 — Arete center: ownership + attachment?**
**KP:** There is an **Arete Development Center selection step inside registration (steps 1–10)**; default is the company center ("Arovolife Arete Development Center"). The company **manually selects** eligible owners via interview and assigns centers by **PIN code**. Owner earns **3%** on BV of distributors who selected that center, capped at ₹1 lakh/month.
**Our state:** touches the registration flow + Phase-7 ADC. Parked. **Open follow-ups:** add the step now? changeable later? back-fill existing distributors? (Round 2, Q9).

**Q11 — Are the big percentages hard caps or targets?**
**KP:** GSB is score-based — a per-score value = total daily BV ÷ total scores, **tentatively ₹360/score**; reconciled against turnover after the plan is finalized. Mentorship 1.5% is a pool target, reconciled similarly. (Re-confirmed the score/bonus table incl. **Slab 7 = score 167 → ₹60,120**.)
**Decision (Preetham, 2026-06-27):** build GSB as a **fixed ₹360/score** (matches the explicit table), with ₹360 as an admin-adjustable rate reviewed periodically — **not** a floating daily pool.
**Our state:** ✅ matches the build. Slab 7 already applied (167 / ₹60,120). **Round-2 Q10 confirms this interpretation with KP.**

---

## Round 2 — ANSWERED by KP (2026-06-27)

**Rank Bonus**
- **A1 — Total is 20%, not 21%.** Corrected pool %s: **7 / 3.4 / 2.7 / 2.2 / 1.7 / 1.2 / 0.9 / 0.6 / 0.3 = 20%** (R2/R3/R4 changed). **APPLIED** to `rank_tiers`.
- **A2 — Rank 1 personal title = Dealer = 7,000 BV.** If matched 3L/3L but personal < 7,000, the distributor tops up the gap via personal purchase that month to get the Dealer title + rank; up to 15,000 personal BV may also be added to the weaker leg for Rank-1 qualification. **APPLIED** (R1 personal-BV 7,000).
- **A2 (cascade) — personal-title ladder** revised: Dealer **7,000**, Distributor **32,000**, Regional **68,000**, National **1,44,000** BV (Retailer/Wholesaler/Global unchanged). **APPLIED** to `gsb_slabs.title_min_bv_paise` + the rank personal-BV requirements that track them.

**Fortune Bonus**
- **A3 — "7 slabs" line:** Rank-1 must complete **any 7 GSB slabs** (one slab max per day, any order, no time limit) and be eligible for those 7 earnings. ⚠️ Conflicts with our seeded `fortune_tiers.slabs_required` of 4/6/8/10/12 — see Round-3 #1.
- **A4 — Matrix is 3-wide × 9-deep.** Confirmed (matches our 10-level seed). Fill continues past level 9 each month but commission is paid only across levels 1–9; resets monthly. ✅ matches.
- **A5 — Placement:** ONE company-wide monthly Fortune tree; members added first-come-first-served by the **time each first qualifies for GSB that month**; 3-wide breadth fill; monthly reset. (Build detail for Phase 6.)
- **A6 / 11 — Big %s are provisional targets**, reconciled after testing; GSB stays **fixed ₹360/score**. ✅ matches build.

**Repurchase**
- **R7 = 1,800, R8 = 2,000** (full ladder: R1 1,000 / R2 1,100 / R3 1,200 / R4 1,300 / R5 1,400 / R6 1,600 / R7 1,800 / R8 2,000 / R9 2,300; non-ranked 600).
- **Wallet-to-zero + calendar month:** personal monthly cycle is anchored to the **Retailer-achievement date** (e.g. 5th→4th). Through the month the repurchase wallet (funded by eligible income) is spent on personal-need products; it must be **exactly zero** by the end date, with own-money top-up if eligible-income purchases fall short of the rank's required BV.
- **Grace:** within the 7 extra days income is **calculated but held** (not released to bank); if still unmet after 7 days it is **permanently forfeited** for the lapsed period and resumes only from the day repurchase is finally completed.

**Cancelled orders / BV reversal**
- **No clawback** of paid bonus; reverse the **BV** from **future** accumulation on the **same leg**; negative-carry allowed; reversal mirrors the **whole upline chain** to the top company ID.

**Caps & deductions**
- **₹50L cap:** ADC + Lifetime Awards are **excluded**. The other four are naturally bounded (GSB max ≈ ₹18,03,600/mo = ₹60,120×30), so **Rank income is trimmed** to bring the total to ₹50L.
- **Admin + TDS on non-cash awards:** apply **only to cash/cheque** releases; **non-cash gifts/goods carry no admin charge or TDS**. **APPLIED** — `applies_to_awards` default → **false**.

**Lifetime Awards**
- Full per-rank reward catalog + budgets supplied (R1 ₹15k → R9 ₹2.25Cr; see Q9 in this doc). Release timing: **immediate R1–2, after 2× re-proof R3–5, after 3× R6–9, once per lifetime.** (18.5% hard-cap question still unanswered — Round-3 #2.)

**Arete**
- Add the **center-selection step to registration now**; default = company center; **changeable later via OTP** (reuse `Shared\Otp\OtpService`); not locked. (Already-registered handling implied default — Round-3 #3.)

---

## Round 3 — OPEN (to send KP)
1. **Fortune eligibility slabs per rank.** A3 says Rank 1 needs **7** GSB-slab achievements, implying the series **7/10/13/16/19** (ranks 1–5). Our build currently uses **4/6/8/10/12**. Confirm the definitive series.
2. **Lifetime Awards 18.5%** — is it a **hard company-wide budget cap** on total reward cost, or just a label?
3. **Arete for already-registered distributors** — do they stay on the **default company center** until they choose one via OTP? (Implied, not stated.)

---

## Already settled (confirming back to KP)
- **GSB Slab 7** built with score 167 / bonus ₹60,120, per your table.
- **Repurchase wallet** = 10% of last month's GSB + MB + GBB + Rank + Fortune, capped ₹10,000.
- **Repurchase date** = each distributor's personal Retailer-title anniversary.

---

## Deviations tracker
1. **Admin charge + TDS scope** — per-bonus admin toggle; ADC charged; **Lifetime Awards now exempt (non-cash)**. ✅ **DONE.**
2. **Mentorship** → true per-slice bracket split + ₹2,70,001 floor boundary. ✅ **DONE.**
3. **Rank pool %s → 20% total** (R2 3.4 / R3 2.7 / R4 2.2). ✅ **DONE** (seed + dev DB).
4. **Personal-title ladder** Dealer 7k / Distributor 32k / Regional 68k / National 1.44L + matching rank personal-BV. ✅ **DONE** (seed + dev DB).
5. **Per-rank graduated repurchase BV** (R1–R9 1,000…2,300) + 7-day grace/hold/forfeit engine + Retailer-anniversary anchor. *(Parked — repurchase engine, later phase. Fully specified now.)*
6. **₹50L monthly aggregate cap** — exclude ADC + Awards; trim Rank. *(Parked — payout orchestration.)*
7. **BV reversal:** no clawback; reverse future BV on same leg; negative-carry; whole upline chain. *(Parked — refund pipeline.)*
8. **Lifetime Awards** catalog + release timing (2×/3× re-proof, once per lifetime). *(Parked — Phase 5.)*
9. **Arete** registration center-selection (default company) + OTP change + manual PIN assignment. *(Parked — registration + Phase 7.)*
10. **Fortune** single company-wide monthly tree, time-ordered placement, levels 1–9 commission. *(Parked — Phase 6; slabs-required pending Round-3 #1.)*
