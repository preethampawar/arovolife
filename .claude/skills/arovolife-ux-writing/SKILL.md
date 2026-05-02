---
name: arovolife-ux-writing
description: Voice, tone and content rules for any user-facing string in the Arovolife platform — buttons, error messages, emails, SMS, push notifications, agreement popups. Strict anti-mis-selling guardrails. Use whenever writing or reviewing copy.
---

# Arovolife UX Writing — reference

## Voice

- Plain English (and plain Hindi where translated). Sixth-grade reading level.
- Respectful and matter-of-fact. Never hyped.
- Never imply earnings, never compare a person's likely income to anyone else's.

## Words you may use

- "join", "register", "complete your registration"
- "income from product sales"
- "you can earn" — only when followed by *"by selling products"* and never with a number.
- "commission" / "bonus" — always explained as "based on the products you sell".
- "cooling-off period" — exact phrasing from T&C; always 30 days.

## Words you may NOT use

| Forbidden | Why | Allowed alternative |
|---|---|---|
| "guaranteed income" | Mis-selling (DSR 5(1)(d)) | (do not use any income-guarantee phrasing) |
| "passive income", "earn while you sleep" | Implies income without work | "income from your product sales and your team's product sales" |
| "lakh per month", "crore", any currency forecast | Income projection | (do not project income) |
| "join now to earn" | Implies enrolment-based earning | "join to start selling products" |
| "downline pays you" | Implies commission for recruitment | "commissions are calculated from product sales in your team" |
| "MLM", "network marketing pyramid" | Confusing / pejorative | "direct selling" |
| "Aadhaar number" displayed in plain | DPDP / UIDAI | masked: `XXXX-XXXX-1234` |
| "PAN number" displayed in plain | DPDP | masked: `XXXXX1234X` |

## Error messages

Pattern: *what happened* → *why* → *what to do next*.

✅ Good:
> "We can't find a sponsor with that ID. Please double-check the ID with the person who invited you, or paste the full invite link they sent you."

❌ Bad:
> "Sponsor not found."

## Confirmation copy

- Always tell the user what will happen, not just "Are you sure?"
- Always offer a way back.

✅
> Cancel registration?
> If you cancel, your information will be deleted within 30 days and you can start again any time. No refund needed because you have not been charged anything to join.
> [Yes, cancel registration]   [No, keep going]

## Cooling-off messaging

- Phrasing must be exactly: *"You have 30 days from the date of approval to cancel your Direct Seller agreement and receive a full refund of any product purchases made during this period. This is your cooling-off period under the Consumer Protection (Direct Selling) Rules, 2021."*
- Display the remaining days as a banner: *"Cooling-off ends on DD MMM YYYY (N days left)."*

## Orientation video gate copy

> Please watch the orientation video. We need to be sure you have understood how Arovolife works before we activate your registration. This is a requirement under our Code of Ethics.

(Add: *"You can pause and resume — your progress is saved."*)

## Side-pick (when Placement Strategy = `custom`)

> Choose which leg of `<placement_id>` to place the new joiner under. This decision affects the structure of your team and cannot easily be changed after registration.
> ( ) Left leg   ( ) Right leg

Never use the words "stronger" or "weaker" for legs in the public UI.

## Email subjects (sample)

- Welcome — `Welcome to Arovolife — your ADN is <ADN>`
- Cooling-off D-20 — `A reminder about your 30-day cooling-off window`
- Cooling-off D-7 — `Your cooling-off window ends in 7 days`
- Cooling-off D-1 — `Last day of your cooling-off window`
- Line-change approved — `Your line-change request has been approved`

## SMS / WhatsApp limits

- ≤ 160 chars for SMS.
- Always include sender ID `AROVOL`.
- Never include OTP and a clickable link in the same message (anti-phishing).
