# T26 — Public site as a first-time visitor (guest, Chrome, staging)

Verdict: PASS-with-notes

Environment: `https://phplaravel-1611779-6390605.cloudwaysapps.com`, Chrome tab 1049837966, guest
(no session). Run 2026-09-10, ~12:25–12:50 IST. No login performed, no account created.

⚠ At the start of the run the shared Chrome profile still carried a **logged-in admin session
("VJ", super-staff)** — the public home page rendered an "Admin Console" link. I logged that
session out (`POST /logout`) before doing any of the checks below, so every result is a true
guest result. See Mutations.

## Checks

| # | Check | Result | Evidence |
|---|-------|--------|----------|
| 1a | Home loads < 3 s | PASS | `PerformanceNavigationTiming.duration` = 751 ms (cold), 168 ms / 100 ms (warm). TTFB 137 ms. |
| 1b | No console errors on home | PASS | `read_console_messages` (pattern `.*`, after a tracked reload + 3 s wait) → "No console messages found" on every page visited in the session. |
| 1c | All 6 product categories present incl. "Agri Care" | PASS | Home "Our products" section lists Health Care, Skin and Beauty, Personal Care, Home Care, **Agri Care**, Lifestyle. (Note: `/shop` shows a 7th, "Food and Beverages" — see D-06.) |
| 1d | Banners render | PASS | Hero carousel renders, 3 slides ("Direct Selling, Done Right", "arovolife Shopping Mall", slide 3), prev/next + dots work. Screenshot: desktop 1308×766. 0 broken images (`[...document.images].filter(!complete‖naturalWidth==0)` → `[]`). |
| 1e | Footer has grievance / helpline / email / website | PASS-with-notes | Footer: `tel:+918886662949`, `mailto:support@arovolife.com`, "Grievance Redressal" → `/p/grievance`, plus Ethics / DSA / Privacy / Compliance Documents. **No Grievance Officer name in the footer itself** (it is on `/p/grievance`), and **no refund/cooling-off or shipping policy link** — see D-03. |
| 1f | Brand "arovolife" lowercase in copy | PASS | Regex over rendered home HTML: `arovolife` × 20, `Arovolife` × 2 — both instances are the legal entity "Arovolife Private Limited". All-caps eyebrows ("WHY AROVOLIFE") are CSS `text-transform`, source is lowercase. |
| 2a | Guest can browse product list + product page | PASS | `/shop` 200 (141 ms), 13 variants across 7 category rails. `/shop/p/immunity-booster` 200 (330 ms), price, HSN, "Inclusive of all taxes", 30-day returns, GST invoice, product facts. |
| 2b | Guest checkout OFF → sign-in required to buy | PASS | "Proceed to Checkout" as a guest → `302 /login` with the message **"Please sign in to complete your purchase."** Matches the setting's documented semantics (`AdminSettingsController` line 547: "Turn off to require sign-in before placing an order"). |
| 2c | No "add to cart" path works without login | **PARTIAL — see D-01** | A guest CAN add to cart: `POST /shop/cart/add` succeeds, banner "Added to cart.", full cart with Subtotal ₹465.25 / GST ₹83.75 / Shipping ₹60.00 / Total ₹609.00, promo-code box and a live "Proceed to Checkout" button. Prices (MRP + discounted) are visible to guests throughout. Only the checkout step is gated. |
| 2d | No e-commerce marketplace links (hard rule 7) | PASS-with-notes | Zero off-site links on `/` and `/shop` (`[...document.querySelectorAll('a')].map(a=>a.href)` filtered to non-app hosts → `[]`). But home banner 2 is headlined **"arovolife Shopping Mall"** — marketplace framing on a direct-selling site; see D-05. |
| 3 | Public FAQ `/faq` | N/A — by design | `/faq` returns **404** for a guest. `PublicFaqController::index` line 39: `if ($this->settings->faqIsMembersOnly()) { abort_unless(Auth::check(), 404); }` — members-only by default, and the class docblock says "A public FAQ is public copy". Not a defect. Logged-in FAQ belongs to T25. |
| 4a | Footer-linked policy pages open (200) with content | PASS | `/p/terms` 200 / 31,103 chars · `/p/privacy` 200 / 20,109 · `/p/grievance` 200 / 15,065 · `/p/ethics` 200 / 16,853 · `/p/compensation` 200 / 24,676. All < 110 ms. |
| 4b | Refund / cooling-off / shipping pages | **FAIL — see D-03** | `/p/refund` 404, `/p/returns` 404, `/p/cooling-off` 404, `/p/shipping` 404. `ContentPageSeeder` only defines `ethics`, `terms`, `grievance`, `compensation`, `privacy`. |
| 4c | No placeholder text ("Lorem", "TODO", "[Name]", …) | PASS | Scanned all 5 pages for `Lorem, TODO, TBD, [Name], XXXX, PLACEHOLDER, FIXME, To be updated, To be appointed, Coming soon, [•]` → **0 hits on every page**. Officer names are populated: Grievance Officer **G. Shankar** (grievance@arovolife.com, +91 88866 62949), DPO **G. Shankar** (dpo@arovolife.com), Nodal Officer **L. Rajender**. **No page still shows a placeholder officer name.** (Separate note: the same person holds Grievance Officer and DPO — see D-04.) |
| 4d | Prints cleanly with the centred helpline \| email \| website footer | **FAIL — see D-02** | None of the 5 policy pages has a Print / Save-as-PDF control (`[...document.querySelectorAll('button,a')]` matching /print\|pdf\|download\|save/ → `[]` on each), and `resources/views/content/show.blade.php` contains no print block. The only views with `window.print` are invoice, profile-stats, membership card and the DSA application — all authenticated. A browser Ctrl-P of a policy page therefore prints without the mandated centred contact footer. |
| 5a | Grievance form opens as a guest, required fields enforced | PASS | `/p/grievance/form` 200. Copy: "Anyone may use this form — you do not need an arovolife account." `checkValidity()` on empty form → `false`, invalid: `category` ("Please select an item in the list"), `subject`, `body`. Name/email/phone optional (anonymous filing supported). 13 categories incl. "Selling arovolife products on e-commerce sites". PII guard note: "Never include passwords, OTPs or your full Aadhaar number." DPDP §5 notice block present. |
| 5b | Submit one test grievance → tracking id | PASS | Confirmation modal "Register a formal complaint / Submit this grievance?" → Confirm → `/p/grievance/submitted`, **complaint number `GRV-260910-2PF6K`**, "First substantive response by Wed, Sep 16, 2026" (5 working days incl. Sat), "Resolution by Sat, Oct 10, 2026" (30 days). Both SLAs match the published policy. |
| 5c | `/grievance/track` shows it | PASS | `/grievance/track` with `GRV-260910-2PF6K` + `qa-tester@example.invalid` → status **Acknowledged**, category "Something else", filed 10 Sep 2026, "Currently with: Customer care agent", resolution due 10 Oct 2026, history rows "Status changed — Received via Web form. 10 Sep 2026, 12:33" and "Acknowledged — Acknowledgement issued with the complaint number. 10 Sep 2026, 12:33". Reply + escalate controls present. |
| 6a | Registration entry states joining is free | **PARTIAL — see D-07** | `/join` (the actual first screen) asks only for Sponsor ADN + Placement ADN and **says nothing about joining being free**. The free-of-cost line lives on the next screen (`step1-account.blade.php` line 10: "Registration is **free of charge**") which I could not reach live — see 6d. Home page does carry "Registration is free. No payment required at signup." |
| 6b | No product/kit pre-added | PASS | `/join` and `/join?sponsor=…` contain only `_token`, `sponsor_adn`, `placement_adn`. Cart badge stayed at its prior value; no SKU added anywhere in the wizard entry. |
| 6c | Orientation described as mandatory | PASS-with-notes | `/join` CTA reads "Continue to Orientation →" but does not use the word *mandatory*; the orientation screen itself is headed "Mandatory Orientation" (`step2-orientation.blade.php` line 7) — verified in source only, not live (6d). |
| 6d | No income implication on the entry screens | PASS | Word scan of the rendered `/join` page for `earn, income, potential, per month, ₹, profit, bonus, commission, payout` → **0 hits**. |
| 6e | Referral link shows the sponsor's name only | PASS | The referral param is `?sponsor=` (not `?ref=` — `?ref=` is silently ignored and leaves the field blank; that param belongs to the shop's Easy Purchase link). `/join?sponsor=444555666` → `sponsor_adn` pre-filled and `readonly`, live lookup renders "✓ **Arovolife Private Limited**" — name only, no ADN owner PII, no email/phone/rank. |
| 6f | Stopped before creating any account | PASS | No POST to `/join` or `/register` was made. Wizard steps 2+ were unreachable — every known staging ADN is placement-full (see N-2), so `/register?sponsor=X&placement=X` redirects to `/contact-us?reason=placement_full` for all 7 reserved ADNs. Handled gracefully with a clear message and a lead form. |
| 7a | Login page renders | PASS | `/login` 200 (95 ms). ADN field (9-digit), password with show/hide, "Remember me". |
| 7b | Wrong password → generic error, no user enumeration | PASS | Non-existent ADN `000000001` + wrong password → "**These credentials do not match our records.**" Existing ADN `444555666` + wrong password → **byte-identical message**. No timing/message difference, no "no such account". |
| 7c | Forgot-password link exists | PASS | "Forgot?" → `/forgot-password` (200), plus "Forgot your ADN?" → `/find-my-id` (200). |
| 8 | `/announcements` as a guest | PASS | `302 → /login` (214 ms). Login-only, renders the sign-in page, no error, no stack trace. |
| 9 | Responsive at phone width (390×844 → 407 px viewport) | PASS-with-notes | Home: `scrollWidth 407 == clientWidth 407` — **no horizontal scroll**. Product page: same. Hamburger nav, single-column category grid, sticky Add-to-Cart row all render correctly. One overlap: **carousel prev/next arrows sit on top of the hero paragraph text** — see D-08. |
| 10 | 500s / blank pages / missing images / mixed content / console errors / slow loads | PASS-with-notes | 22-URL sweep: all 200 except `/faq` 404 (by design) and `/announcements` → `/login`. **No 500, no blank page.** Slowest response 214 ms; nothing near 3 s. Zero `http://` sub-resources (no mixed content). Zero console messages all session. Two observations: external `fonts.googleapis.com` (×2) and `googletagmanager.com` returned **503** on this run — third-party, most likely local network/extension interception rather than a site defect; and the cart thumbnail hot-links `picsum.photos` — see D-09. |

URL sweep (status | time | bytes):
```
/ 200 100ms 78632          /about-us 200 104ms 65159      /news 200 108ms 23168
/blogs 200 106ms 23167     /seminars 200 99ms 23181       /contact-us 200 94ms 39097
/shop 200 141ms 96877      /compliance-documents 200 104ms 24105
/p/ethics 200 102ms 40633  /p/terms 200 99ms 56969        /p/privacy 200 97ms 45376
/p/grievance 200 99ms      /p/compensation 200 109ms      /p/grievance/form 200 101ms
/grievance/track 200 104ms /join 200 100ms                /login 200 95ms
/forgot-password 200 103ms /find-my-id 200 110ms          /arovo-hub 200 114ms
/faq 404 108ms (members-only, by design)                  /announcements 200 214ms -> /login
/p/refund 404  /p/returns 404  /p/cooling-off 404  /p/shipping 404
```

## Defects

**D-01 — Medium (UX/compliance-adjacent) — a guest builds a full priced cart before being told to sign in.**
Repro: logged out → `/shop/p/immunity-booster` → "Add to Cart" → cart page → "Proceed to Checkout".
Expected (per the task brief and the Atomy "members-only buying / after-login pricing" decision):
the storefront asks a guest to sign in to see distributor pricing and to buy; no add-to-cart path
works without login. Actual: prices, discounts, GST, shipping, free-shipping threshold, promo-code
box and the whole cart are all available to an anonymous visitor; the sign-in wall appears only at
`Proceed to Checkout` ("Please sign in to complete your purchase"). The behaviour matches the
`commerce.guest_checkout.enabled` **setting** as documented in code ("require sign-in before placing
an order"), so this is a product-intent question, not a code bug — but it does not match the brief's
expectation, and there is no separate setting anywhere that hides pricing from guests
(`grep "'commerce\." AdminSettingsController` returns 13 keys, none about catalogue/price visibility).
Decide: is public pricing intended, or should the catalogue be members-only?

**D-02 — Low — public policy pages have no Save-as-PDF path and no print contact footer.**
Repro: open `/p/terms` (or privacy / grievance / ethics / compensation) → no Print or Save-as-PDF
control anywhere; Ctrl-P prints the page with the site chrome and without the mandated centred
`helpline | email | website` footer. Expected (project convention "Downloadable pages contact
footer"): every printable / Save-as-PDF page ends with a centred clickable helpline | email |
website footer. Actual: `resources/views/content/show.blade.php` has no print block, and the only
views carrying `window.print` are the invoice, profile stats, membership card and DSA application —
all behind login. Statutory documents that consumers will want to keep a copy of are exactly the
pages that should print cleanly.

**D-03 — Medium (compliance) — no public refund / cooling-off / shipping policy page, and none linked from the footer.**
Repro: `/p/refund`, `/p/returns`, `/p/cooling-off`, `/p/shipping` all 404; the footer's Legal column
lists only DSA, Privacy, Grievance, Compliance Documents. Expected: DSR 2021 requires the direct
selling entity to display its return, refund, exchange, warranty and cancellation policy on its
website; the 30-day cooling-off is a hard rule (§5) and the product page already promises "30-day
returns" and "Cooling-off window on every order" with nothing to click through to. Actual: the
cooling-off and refund terms exist only inside the 31,000-character `/p/terms` document, with no
standalone page and no footer link. Recommend a `refund` (or `cooling-off`) content page seeded and
linked, and the product-page "30-day returns" badge linked to it.

**D-04 — Low (compliance, for the compliance officer) — one person is both Grievance Officer and Data Protection Officer.**
`/p/grievance` names **G. Shankar** as Grievance Officer *and* as DPO (dpo@arovolife.com), with
**L. Rajender** as Nodal Officer. DPDP 2023 and the platform's own separation-of-duties principle
argue for these being different people — the DPO is the escalation route for complaints about how
the Grievance Officer's own team handled personal data. No placeholder names remain (that launch
blocker looks discharged on these pages); this is a role-assignment question, not a copy bug.

**D-05 — Low (compliance copy) — home banner 2 is headlined "arovolife Shopping Mall".**
Repro: home page, carousel slide 2/3. Hard rule 7 is "no e-commerce listings, no offline retail";
"Shopping Mall" is marketplace framing and reads as a retail storefront rather than a direct-selling
channel. Body copy underneath is fine ("Browse our curated range … backed by a GST invoice on every
order"). Suggest a heading that does not borrow marketplace language. Flagging for T40.

**D-06 — Low (data/consistency) — the shop has 7 category rails, the home page 6.**
`/shop` adds **"Food and Beverages"** (1 product, "arovolife Green Tea", ₹1,500) which does not
appear in the home-page category grid or in the recorded 6-category set (Health Care, Skin and
Beauty, Personal Care, Home Care, Agri Care, Lifestyle). Either the home grid is stale or the
category is test data that should not be live.

**D-07 — Low (compliance) — the first registration screen does not say joining is free.**
Repro: `/join` (linked from "Register with us" in the top nav and from every "Become a Direct
Seller" CTA). Expected (hard rule 1, T&C §4): the registration entry states joining is free of cost.
Actual: `/join` only collects Sponsor ADN + Placement ADN; the "Registration is free of charge" line
is on the *next* screen (step 1 Account). The home page carries it, but a visitor arriving on a
referral link lands on `/join` directly and never sees it. One line on `/join` closes this.

**D-08 — Low (layout, mobile) — carousel arrows overlap the hero paragraph at phone width.**
Repro: resize to 390×844 → home page → slide 2. The circular ‹ and › buttons are vertically centred
on the slide and sit directly on top of the body copy ("…health and food products — responsibly
sourc**‹›**d…"), obscuring characters on both edges. Expected: arrows below the copy, or hidden on
touch widths in favour of swipe + dots. No horizontal page scroll otherwise (scrollWidth ==
clientWidth == 407 on both home and the product page).

**D-09 — Medium (data / privacy) — the cart line thumbnail hot-links picsum.photos.**
Repro: add any seeded product to the cart → the line-item thumbnail `src` is
`https://picsum.photos/seed/immunity/800/800` (a third-party random-image service), while the same
product's PDP hero is the real S3 gallery image
(`arovolife-staging.s3…/products/gallery/d9adedb6-….jpg`). Two problems: (a) every storefront
visitor's IP and referrer are sent to an unrelated third party, with no consent basis and nothing in
the Privacy Policy about it; (b) the customer sees an unrelated stock photo next to the product they
just added, and it will not match the PDP. Source is seed data — `database/seeders/ProductCatalogSeeder.php`
lines 83/92/101 set `image_url` to picsum URLs — but the code path is real: the cart renders
`image_url` verbatim while the PDP renders the gallery, so the two views can always disagree. The
cart `<img>` also has an empty `alt`. Cross-refs F01 (T02) — the staging catalogue needs real data.

**D-10 — Low (data) — product pack artwork does not match the listing.**
`/shop/p/immunity-booster` is titled "arovolife Immunity Booster — 60 capsules. Zinc, C &
elderberry." The hero image is a bottle labelled "**AROVITA — arovolife VitaPlus+ — 30 TABLETS**".
Different product name, different brand mark, different count. Also four live products are unnamed
test rows with no description: `detox` (₹1,500), `tootpast` (sic, ₹100), `agri` (₹1,600), `higrow`
(₹1,599). Staging test data, but it is publicly visible.

**D-11 — Low (hygiene) — an admin session was live in the shared Chrome profile.**
When this task started, the public home page rendered an "Admin Console" link because the browser
still held a logged-in super-staff session ("VJ"). Not a site defect — the `@auth` /
`isSuperStaff()` gate in `partials/public-topnav.blade.php` is correct — but it means an earlier
browser task did not log out, which would have silently invalidated any guest-perspective check.
Flagging so the Phase-2/3 browser tasks each verify their own auth state before trusting what they see.

## Mutations made on staging

| # | What | Detail |
|---|------|--------|
| M1 | Logged out the pre-existing admin session | `POST /logout` as user "VJ" (super-staff). Required to run this task as a guest; the brief also requires logging out at the end. State now: no session in this Chrome profile. |
| M2 | **One grievance created** | `grievances` — reference **`GRV-260910-2PF6K`**, subject "QA test 2026-09-10 — please ignore", category `other`, name "QA Tester", email `qa-tester@example.invalid`, phone 9000000000, source Web form, status **Acknowledged**, filed 10 Sep 2026 12:33 IST, resolution due 10 Oct 2026. Also created its 2 history/timeline rows and (attempted) an acknowledgement email to `qa-tester@example.invalid` (invalid TLD — will bounce/fail; expect one ElasticEmail failure in the log around 12:33 IST, cross-ref F03). **Please close/delete this grievance before UAT.** |
| M3 | Guest cart add/clear ×2 | Added variant `AV-IB-001-V1` to the guest session cart twice (once to test add-to-cart gating, once to capture the thumbnail URL) and cleared it both times. Final state: cart empty ("Cart cleared." / "Your cart is empty."). No order, no payment, no BV. |
| M4 | 2 failed login attempts | `000000001` (non-existent) and `444555666` (reserved account) each with a wrong password, to test enumeration. One failed attempt on `444555666` — below any lockout threshold, and I did not touch the QA password set by T01. May appear in the auth/audit log. |
| M5 | Browser window resized | 1308×766 → 390×844 → restored to 1400×900. |

Nothing else was written. No `.env`, no artisan command, no DB write outside the app's own request handling.

## Notes for the orchestrator

1. Verdict is PASS-with-notes, not FAIL: nothing 500s, nothing leaks money data to a guest, no
   income projection appears anywhere on the public site, and the compensation disclosure carries
   the right anti-projection language ("Most Distributors do not reach the higher slabs … many earn
   nothing"). The two items I would not ship without are **D-03** (no public refund/cooling-off
   policy page) and **D-09** (storefront hot-linking picsum.photos).
2. **Registration wizard steps 2–10 are unreachable on staging as a guest**: all 7 reserved ADNs
   (444555666, 973708897, 177536419, 957327353, 608628172, 920536893, 946362630) are placement-full
   and spillover is off, so every referral link dead-ends at `/contact-us?reason=placement_full`.
   Checks 6a/6c were verified in source, not live. If T20–T25 need the wizard, someone must supply
   an ADN with an open Genos slot (I could not query the DB — the SSH/MySQL call was blocked by the
   sandbox classifier).
3. `?ref=` does **not** pre-fill the sponsor on `/join`; the registration referral param is
   `?sponsor=` (dashboard builds `url('/join').'?sponsor='.$adn`). Worth confirming no printed or
   shared collateral uses `?ref=` for registration.
4. `/faq` 404ing for guests is intentional (members-only), not a defect — hand the FAQ to T25.
5. `fonts.googleapis.com` and `googletagmanager.com` both returned 503 during this run. Low
   confidence that this is the site's fault (third-party, likely local interception) — worth one
   confirmation from another browser task before anyone chases it.
6. Please have someone delete grievance `GRV-260910-2PF6K` when the QA window closes.
