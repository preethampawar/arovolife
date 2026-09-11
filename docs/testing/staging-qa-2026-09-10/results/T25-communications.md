# T25 — Distributor communications (announcements, FAQ, messages, my requests, grievances)

Verdict: **PASS-with-notes**

Tested on https://phplaravel-1611779-6390605.cloudwaysapps.com (origin/main 6f114500), 2026-09-10 18:55–19:25 IST.

**Session mode:** No staff session existed in Chrome when I started (`/dashboard` rendered the guest header with "Sign in"). Per the SESSION RULE UPDATE I therefore signed in myself, directly as QA distributors — ADN 444555666 (user 2, distributor 1, tree root), ADN 608628172 (user 6) for the recipient-side checks, and ADN 973708897 (user 3) for the unread-badge check. **I signed out at the end** (`/logout`, page returned to "Sign in — arovolife"). No impersonation was used; no other session was disturbed.

**Environment facts read from staging before testing (settings table has no `messaging.*` / `grievance.*` / `announcements.*` / `faq.*` rows, so registry defaults apply):**
messaging.audience = `downline_upline`; rate limit 60/hour and 20/recipient/day; block list on; reporting on; max body 4000 chars. faq.members_only = true. Grievance SLA: ack 48 h, first response 5 working days (Mon–Sat), resolution 30 days (60 third-party), status update every 15 days. Flags `MessagingFeature`, `AnnouncementsFeature`, `FaqLibraryFeature`, `DistributorRequestsFeature` are all ON globally.

---

## Checks

| # | Check | Expected | Observed | Evidence | Result |
|---|---|---|---|---|---|
| 1 | `/announcements` list | Only live (published, published_at ≤ now, not expired) announcements, addressed to me | "There are no announcements right now." | `SELECT COUNT(*) FROM announcements` = **0**; page text at `/announcements` | PASS (empty) |
| 2 | Draft/archived announcements hidden | Drafts/archived never listed | Not exercisable — 0 rows on staging. Verified by code: `Announcement::scopeLive()` (`app/Modules/Content/Models/Announcement.php:93-100`) filters `status = published`, `published_at <= now`, `expires_at NULL or > now`; `AnnouncementService::forUser()` layers the audience predicate | code | PASS (by code) — see F5 |
| 3 | Announcement read-marking / id-guessing | Opening marks read; unaddressed id → 404 | Not exercisable (0 rows). Code: `AnnouncementController::show()` re-runs `forUser()` and `abort_unless($addressed, 404)` before `markRead()`; `markRead` is `firstOrCreate` (idempotent) | `app/Modules/Content/Http/Controllers/AnnouncementController.php:37-57` | PASS (by code) — see F5 |
| 4 | Unread badge | Bell shows unread announcements + messages | Header link rendered as **"Notifications (1 unread)"** for ADN 973708897 (message 11 unread); `/messages` row showed a per-thread unread count of 1 | header a11y label; `messages.id = 11, read_at NULL` | PASS |
| 5 | Announcements surfaced in nav | Reachable in-app | Sidenav → Overview → **Announcements** (`ref_97`), flag-gated in `partials/distributor-sidenav.blade.php:19` | sidenav | PASS |
| 6 | Announcement copy — no income projection | No earnings claims | No content to inspect (0 rows). Admin-side copy gate is T35/T36 scope | — | N/A |
| 7 | `/faq` as a guest | Members-only ⇒ 404, no trace | 404 "Page not found — arovolife" while signed out | `/faq` logged out | PASS |
| 8 | `/faq` signed in | Published FAQ entries, grouped | "There are no answers here yet." + working search box | `SELECT COUNT(*) FROM content_pages WHERE type='faq'` = **0** | PASS (empty) |
| 9 | Only published FAQ entries render | Drafts hidden | Not exercisable (0 rows). Code: `PublicFaqController::index()` requires `type=faq`, `status=published`, `published_at NOT NULL AND <= now` | `app/Modules/Content/Http/Controllers/Public/PublicFaqController.php:44-49` | PASS (by code) — see F5 |
| 10 | FAQ in-app link | Linked from the member area | Sidenav → My Account → **FAQ** (`ref_131`) | sidenav | PASS |
| 11 | FAQ dead links | None | No entries, so no links to break. No console errors on the page | console clean | PASS |
| 12 | `/messages` inbox | Own conversations only, no email addresses | ADN 444555666: 1 thread after my test send. ADN 973708897: 2 threads (ADN 248958325, ADN 608628172). Correspondents shown by **name + ADN**, never email | `/messages` page text | PASS |
| 13 | Who is messageable | Sponsor / upline / downline (`downline_upline`) | Confirmed in `MessageService::assertWithinAudience()` — `TeamStatsService::sharesLineage()` over `genealogy_closure` + `sponsorship`, fail-closed if either side has no distributor row; staff always reachable | `MessageService.php:139-170`, `TeamStatsService.php:186-219` | PASS (by code) — see F2 |
| 14 | PII guard — PAN | Refuse `ABCDE1234F` | Refused: *"Please remove the full PAN number. Quote only the last 4 digits — we can find the record from that."* No row written | POST `/messages/6`; `messages` unchanged | PASS |
| 15 | PII guard — Aadhaar | Refuse an Aadhaar-format number | Plain `999988887777` was **accepted** (correct: not Verhoeff-valid — deliberate, documented in `NoRawGovernmentId`). Verhoeff-valid synthetic `9999 9999 9999` was **refused**: *"Please remove the full Aadhaar number…"* | two POSTs to `/messages/6`; `app/Modules/Shared/Rules/NoRawGovernmentId.php:29,84-90` | PASS |
| 16 | Compose-box PII warning | Warn before typing | *"Please do not send a full PAN or Aadhaar number — the last four digits are enough."* under the textarea, and again in the report modal | thread page | PASS |
| 17 | Block (blocker side) | Confirmation modal, row written, honest banner | Modal "Block this person / Stop receiving messages from …? They will not be told they were blocked. You can undo this at any time…" → `message_blocks` id 1 (blocker 2, blocked 6) → banner "You have blocked this person. They cannot send you new messages, and they have not been told." | screenshot; DB row | PASS |
| 18 | Unblock restores | Row removed, Block button returns | `SELECT COUNT(*) FROM message_blocks` = 0; banner "You will receive messages from this person again."; button back to **Block** | DB + page | PASS |
| 19 | Block — sender sees an honest refusal | Refusal that does not disclose the block | As user 6 I blocked user 2; signed in as 444555666 the compose box was replaced by *"You can no longer send messages in this conversation. You can still read what was said."* — no mention of a block, consistent with the promise made to the blocker | `/messages/6` as ADN 444555666 | PASS |
| 20 | Block enforced server-side | Direct POST refused | `POST /messages/6` (JSON, valid CSRF) → **422** `{"ok":false,"message":"This person is not accepting messages from you."}` | fetch from the page | PASS |
| 21 | Report a message | `message_reports` row created | Report modal (category + optional reason, "…is not told who reported it") → flash "Thank you — our team will review this message." → `message_reports` id 1: message_id 12, reported_by_user_id 6, category `income_claim`, status `open` | DB row | PASS |
| 22 | Messaging rate limits | Per-hour and per-recipient-per-day caps | Configured at **60/hour** and **20/recipient/day** (registry defaults; no settings rows). Both > 10, so per the brief the limits were **not exhausted**. Mechanism verified in `assertWithinRateLimits()`: `RateLimiter::tooManyAttempts` on `messaging:send:{from}` (3600 s) and `messaging:send:{from}:to:{to}` (86400 s), checked *after* the audience/block guards so a refused send does not burn quota; staff bypass | `MessageService.php:180-217` | PASS (by code + note) |
| 23 | Thread enumeration by URL (**F25**) | Should be refused | **Confirmed still open.** `MessageController::show()` has no audience or participation check. `GET /messages/{id}` returned 200 for every id tried — 75 "Chat with julee", 73 "Chat with karina", 242 "Chat with VJ", 1 "Chat with Test Admin"; 999999 → 404 | fetch loop from the page | **FAIL (known F25)** — see F1 |
| 24 | `/my/requests` list | Scoped, empty, clear guidance | "You have not filed any requests." + a "What goes where" panel that routes profile changes and misconduct complaints elsewhere. `distributor_requests` = 0 rows | page + DB | PASS |
| 25 | Create a distributor request | One name-correction request created | **BLOCKED.** Every document-bearing request type mandates an upload, and every upload is refused: *"We could not check the documents.id_proof.0 for malware just now. Please try again shortly."* Server log: `Upload refused — malware scanner unavailable … Set CLAMAV_HOST and run clamd`. The only doc-free type is ID cancellation, which closes the ADN — out of QA bounds, not attempted | staging log 2026-09-10 19:13:06 | **BLOCKED** — see F3, F4, F6 |
| 26 | `/my/requests/{id}` show page | Renders my request | Not reachable — no request could be created | — | BLOCKED |
| 27 | `/help` support hub | Routes, named officers, statutory escalation | Four tiles (raise a grievance / my grievances / general enquiry / policy) + Grievance Officer, Nodal Officer, Compliance Committee mailboxes, helpline, and an explicit "you do not have to exhaust our internal steps first" statement naming NCH 1800-11-4000 / 1915 and the CCPA | `/help` | PASS |
| 28 | Create a grievance | Ticket number issued immediately | Confirmation modal "Register a formal complaint" → **GRV-260910-39CCK** (tickets id 2), category `other`, channel `web`, status `acknowledged` | page + DB | PASS |
| 29 | SLA timestamps match published policy | 48 h / 5 working days (Mon–Sat) / 30 days | created 19:15:48 → `sla_acknowledgement_at` **2026-09-12 19:15:48** (+48 h ✓), `sla_first_response_at` **2026-09-16 19:15:48** (Fri 11, Sat 12, skip Sun 13, Mon 14, Tue 15, Wed 16 = 5 working days ✓), `sla_resolution_at` **2026-10-10 19:15:48** (+30 d ✓). Page states 48 h / 5 working days / 30 days (60 third-party, update every 15 days) and shows "Resolve by 10 Oct 2026". Pre-existing ticket 1 recomputes identically | DB `tickets` id 1 and 2; `GrievanceSlaCalculator`, `GrievanceSettingsService:31-35` | PASS |
| 30 | Acknowledgement notification path | Queued and sent without failure | History records "Acknowledged — Acknowledgement issued with the complaint number, 10 Sep 2026 19:15". `jobs` = 0 pending, `failed_jobs` unchanged (latest failure 2026-09-05, an unrelated SMTP daily-limit run), no new laravel.log error at 19:15. 11 `queue:work` processes running | DB + log | PASS (no failure recorded; actual SMTP delivery to `reserved-00@arovolife.local` not verifiable) |
| 31 | Distributor reply on a grievance | Reply appended to history | Confirmation modal → "Your reply has been added." → History gained a **Comment** entry at 19:17 with my text | `/my/grievances/2` | PASS |
| 32 | Cannot see other people's tickets | `/my/grievances/1` refused | **404 "Page not found"** (zero-trace, not 403). `/my/grievances` list shows only GRV-260910-39CCK | `/my/grievances/1` | PASS |
| 33 | Public tracking — correct credentials | Ticket shown | `/grievance/track` with `GRV-260910-2PF6K` + `qa-tester@example.invalid` → full status card + history, "Resolution due 10 Oct 2026" | page | PASS |
| 34 | Public tracking — wrong email | Refused, no enumeration | *"We could not find a grievance matching that complaint number and email. Check both, or write to grievance@arovolife.com quoting the number."* — same message whether the number or the email is wrong | page | PASS |
| 35 | Public tracking throttle | 10 requests / 10 min | Route middleware `throttle:10,10` on both `grievance.track.lookup` and `grievance.track.reply` | `routes/web.php:838-841` | PASS (by code; not exhausted) |
| 36 | Console errors | None | No console messages of any kind across `/my/grievances/2`, `/announcements`, `/messages` | `read_console_messages` | PASS |
| 37 | Page load times | < 3 s | Slowest **`/dashboard` 217 ms**; every other page in scope 131–154 ms | timed fetch loop | PASS |

---

## Findings

### F1 — `GET /messages/{userId}` enumerates the whole user base by name (Medium) — confirms known finding F25
`MessageController::show()` (`app/app/Modules/Messaging/Http/Controllers/MessageController.php:129-158`) performs **no audience and no participation check** — the class docblock states the intent ("any thread where they're one of the two parties") but nothing enforces it. Any authenticated distributor can walk sequential ids and harvest full names.

Repro (signed in as ADN 444555666, from the page console):
```
GET /messages/75     → 200  <title>Chat with julee — arovolife</title>
GET /messages/73     → 200  <title>Chat with karina — arovolife</title>
GET /messages/242    → 200  <title>Chat with VJ — arovolife</title>       (staff)
GET /messages/1      → 200  <title>Chat with Test Admin — arovolife</title> (staff)
GET /messages/999999 → 404
```
Expected: 404 unless the viewer is a party to an existing thread or `canMessage()` is true. Actual: 200 with the target's name.

Mitigating: message *bodies* do not leak — `Message::threadBetween()` is scoped to the pair, so an unrelated thread renders empty. The send path *is* guarded (`MessageService::assertWithinAudience`). No role name leaks (user 242 shows as "VJ", not as the developer role). So this is disclosure of *existence + full name*, not of correspondence.

Secondary: `show()` calls `markThreadRead()` — a write on a GET. Harmless here, but it means a crawler flips read flags.

Not re-investigated further, per brief.

### F2 — Audience restriction is untestable from the QA root account (Medium, coverage gap)
ADN 444555666 is distributor id 1, the tree root: `SELECT COUNT(*) FROM distributors` = 317 and distributor 1 has 316 descendants, so **every** distributor shares lineage with it and the `downline_upline` restriction can never fire from this account. The only non-distributor users on staging (ids 1 and 242) are staff, which `assertWithinAudience()` deliberately always allows. Re-test the refusal from a leaf account (e.g. ADN 973708897 → ADN 177536419, siblings under distributor 1) before sign-off.

### F3 — All document uploads are refused on staging: no malware scanner configured (High for UAT)
Submitting a name-correction request fails validation with:
> We could not check the documents.id_proof.0 for malware just now. Please try again shortly.

Server log, `storage/logs/laravel.log`, 2026-09-10 19:13:06:
> `staging.ERROR: Upload refused — malware scanner unavailable {"attribute":"documents.id_proof.0","reason":"No malware scanner is configured. Set CLAMAV_HOST and run clamd before accepting uploads (security audit T-6.1 finding H-4)."}`

Fail-closed is the right behaviour, so this is an **environment gap, not a code bug** — but it blocks the entire Distributor Requests feature (and, by the same rule, grievance evidence and KYC re-uploads) on staging, so those paths cannot reach UAT. Set `CLAMAV_HOST` and run `clamd` on the staging server, then re-run check 25.

### F4 — Malware-refusal message leaks the raw field path to the user (Low, copy)
`app/app/Modules/Shared/Http/Rules/ScannedForMalware.php:51` interpolates `$attribute` verbatim, producing "We could not check the **documents.id_proof.0** for malware just now." A distributor should see the label they saw on the form ("PAN card (or another government ID)"), not an array path. Same rule is used by grievance attachments and KYC, so the wording is user-visible in three places.

### F5 — Announcements and the FAQ library are empty on staging (Medium, content/coverage gap)
`announcements` = 0 rows, `content_pages WHERE type='faq'` = 0 rows, while both feature flags are ON and both links are live in the sidenav. Empty states are graceful and correctly worded, but nothing in this task could exercise, end-to-end: the draft/archived filter, the rank/status audience filter, read-marking, the announcement half of the unread badge, or the FAQ accordion/anchors/search. All were verified by code instead (checks 2, 3, 9). Seed at least one published + one draft + one archived announcement and a handful of FAQ entries before UAT, or these paths ship untested.

### F6 — A failed request submit discards everything the distributor typed (Medium, UX)
After the malware-scanner refusal on `/my/requests/create`, the form re-rendered with **"Name as it should appear" and "Reason / details" both empty** — the user must retype a free-text reason to retry a failure that was entirely server-side. `MessageController::store()` gets this right (`back()->withInput()`); the distributor-request store path does not.

### F7 — Flash messages render twice on every distributor page (Low, cosmetic)
`resources/views/layouts/app.blade.php:36-38` renders `session('status')`, and individual views render it again (e.g. `resources/views/messages/show.blade.php:46`), so the same green banner appears stacked twice. Reproduced on three separate actions: block ("You will no longer receive messages from this person."), grievance creation ("Your grievance has been registered as GRV-260910-39CCK."), and message report ("Thank you — our team will review this message."). Screenshot evidence captured for all three.

### F8 — Published helpline hours contradict each other (Medium, compliance-adjacent copy)
`/help` states the helpline runs **10:00–18:00 IST, Mon–Sat**. The global site footer on every distributor page states **9:30 am – 5:30 pm, every day except Sundays & public holidays**, and quotes a different contact (`support@arovolife.com` vs the four grievance mailboxes). Published grievance-channel availability is a DSR-2021 disclosure; the two statements must agree, and `GrievanceSlaCalculator` already assumes Mon–Sat working days.

### F9 — Confirmation-modal titles are inconsistent (Low, UX polish)
Blocking uses "Block this person", submitting a request "Confirm request", raising a grievance "Register a formal complaint" — all good. But **adding a reply to a grievance** falls back to the generic "Please confirm" with no impact text. Give it a title and a one-line consequence like its siblings.

---

## Mutations made on staging

Nothing was deleted. Rows created, for cleanup:

| Table | id | Detail |
|---|---|---|
| `messages` | **12** | from_user_id 2 → to_user_id 6, body "QA test 2026-09-10 please ignore. Fake 12 digit 999988887777" (the non-Verhoeff number that the guard correctly allows). Marked read by user 6 |
| `message_reports` | **1** | message_id 12, reported_by_user_id 6, category `income_claim`, reason "QA test 2026-09-10 - please ignore…", status `open`. **Needs closing by an admin (T35/T36) or deleting** |
| `tickets` | **2** | `GRV-260910-39CCK`, subject "QA test 2026-09-10 — please ignore", category `other`, reporter reserved-00@arovolife.local, status `acknowledged`. **Safe to close** |
| ticket events for ticket 2 | — | one "Received via Web form", one "Acknowledged", one distributor "Comment" reply |
| `audit_log` | 3209 | one `auth.login_failed` for ADN 444555666 — a deliberate wrong-password probe to confirm the login form surfaces "These credentials do not match our records." |

Created and then **reverted during the run** (verified back to zero): `message_blocks` id 1 (2 blocks 6) and a second block (6 blocks 2). `SELECT COUNT(*) FROM message_blocks` = **0** at the end.

Nothing created in: `announcements`, `announcement_reads`, `content_pages`, `distributor_requests`, `distributor_request_documents`.

No engine run, recompute, deploy, `.env` change, migration or bulk delete was performed. No setting or feature flag was modified. All DB access was read-only `SELECT` over SSH.

---

## Notes for the orchestrator

- Verdict is PASS-with-notes, not FAIL: the only functional failure is the already-known F25 (F1), and the one blocked path (F3, distributor requests) is a missing staging `CLAMAV_HOST`, not a code defect.
- **Two staging-environment items block UAT for other tasks too:** no malware scanner (F3) kills every upload surface — distributor requests, grievance evidence, KYC re-upload; and empty announcements/FAQ (F5) leave three content paths unverified.
- QA credential note for other agents: ADN 444555666, 608628172 and **973708897** all authenticate with `QaPass!2026` — I confirmed 973708897 works after an earlier tooling artifact made it look broken. Setting login fields via synthetic click+type was unreliable on this form; setting `.value` through the native setter plus an `input` event, then `form.requestSubmit()`, worked every time.
- 444555666 is the tree root, which makes it the wrong account for any downline/upline scoping test (F2). Use a leaf ADN for those.
- Console was completely clean and every in-scope page responded in under 220 ms.
