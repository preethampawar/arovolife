# Distributor Communications

Three surfaces where the company and its distributors talk to each other:
direct messages between distributors, announcements from the company, and the
FAQ library. Each is gated by its own feature flag under **Feature Flags**, and
each is tuned under **Settings → Distributor communications**.

Read this before you change a setting here. Two of these controls are
compliance controls that happen to look like conveniences.

---

## 1. Direct messages

Distributors message each other from their inbox and from the Send Message
action on a Genos or direct-referral card. The channel has been live since
Phase 1.

### The killswitch

**Feature Flags → Distributor messaging.** Unlike every other flag in the
console this one is **ON by default**, because messaging is already a live
feature. Turn it off to close the channel during a harassment or mis-selling
investigation. Nothing is deleted: messages are retained while it is off, and
every thread comes back when it goes back on. While it is off the inbox, the
bell, the Send Message action and this settings block all disappear — a
distributor sees no trace of a channel they cannot use.

### Who may message whom

**Settings → Who a distributor may message.**

- **Restricted (default)** — a distributor may write to their sponsor, their
  upline, their sponsees and their Genos team. Company accounts are always
  reachable, whichever way this is set.
- **Anyone** — the Phase 1 behaviour: any signed-in member may message any
  other.

Setting this to Anyone makes every distributor reachable by every other. The
endpoint takes a numeric user id, so it also makes the distributor base
enumerable by anyone willing to count. That is the standard cross-recruiting
attack, and the standard way one member ends up with a hundred pitches a day.
Change it only with a reason you would be willing to write down.

### Rate limits

Two limits, because they stop two different things:

- **Messages per sender per hour** (default 60) stops a broadcast across many
  recipients.
- **Messages to one person per day** (default 20) stops one member being worn
  down by a single sender who is still inside their hourly quota.

Set either to 0 to remove it. A refused send does not consume the sender's
quota — being told "no" should not cost someone the ability to message a person
they are allowed to message.

Staff are not rate-limited.

Both limits are counted in the cache, which is Redis. Redis on this platform is
shared and runs an eviction policy (ADR-0011), so a counter can in principle be
dropped and a sender's quota reset early. Treat these as anti-nuisance controls
that hold in the ordinary case, not as a hard cap you could prove in a
complaint. Nothing about money rides on them — that is why they are allowed to
live there at all.

### Block list

When on, a distributor can stop hearing from another distributor. Blocks are
one-directional: if A blocks B, B cannot write to A, but A can still write to
B. The blocked person is not told. **Company accounts can never be blocked** —
compliance has to be able to reach a member about their own account.

### PAN and Aadhaar guard

**There is no setting for this one.** A message body carrying a full PAN or a
Verhoeff-valid Aadhaar number is always refused. It was briefly a switch; it
should not have been. Hard rule 8 has no off position, and an audit trail on the
settings edit does not un-write somebody's Aadhaar into a table in plaintext.

A member asking their upline to "check my KYC, my PAN is …" is the ordinary
case, not the malicious one, which is exactly why a warning under the textarea
would not do. The message is refused and the sender is asked to quote
the last four digits. The same rule guards grievance free text, the reason
someone types when reporting a message, and the note a reviewer writes when
closing a report.

The guard applies to staff too. Hard rule 8 has no staff exception, and an
admin pasting a PAN into a chat is the same leak.

### Reported messages

**Admin → Reported messages.** A distributor can report a message they
received; it lands here for operations or compliance to review.

This queue is the only route by which anything said in a private message
becomes visible to the company. The copy audit that guards the public site
scans our own templates — it cannot scan what one distributor types to another.
An income claim made in a chat arrives here or nowhere.

Working a report:

1. Open it. You see the reported message and three messages either side for
   context — not the whole thread. One line is often unreadable on its own; the
   whole history is more than judging one complaint needs.
2. **Every open is audit-logged.** A control that lets staff read members'
   messages needs its own trail.
3. Record what you found and what you did, then close it as **Reviewed**
   (nothing further needed) or **Actioned** (the account was dealt with).

Closing a report never changes the message. Nothing in this screen edits or
deletes what was said: the record of a mis-selling claim is the evidence for
whatever account action follows, and an evidence store staff can edit is not
one a regulator would accept. Apply consequences with the account tools on the
distributor's own page.

`messaging.moderate` excludes **admin-finance** (R-17), for the same reason
grievances do: the reports that matter name members of staff.

### What the privacy law requires of this queue

Reading a member's private message is a real intrusion, and DPDP 2023 only
permits it because it is narrow, disclosed and bounded. All three parts have to
stay true:

- **Narrow.** Only a message someone reported, plus three either side. There is
  no bulk scanning of unreported messages anywhere in this platform and none is
  planned — scanning everything would be a proportionality problem of its own.
- **Disclosed.** Privacy Notice §4.5b names this purpose and the three-message
  window; the sender is told on the compose screen, before typing, that a
  message they send can be reported and read. If you change what staff can see
  here, §4.5b has to change with it.
- **Bounded.** Privacy Notice §5 gives every table an end: messages 24 months,
  reports and the moderation record 7 years, blocks for the life of the block,
  announcement reads 24 months. `messages:purge` runs weekly (Sunday 03:20 IST)
  and enforces the 24-month rows. A **reported** message is held back from the
  purge regardless of age — it is the evidence for the report.

The notice text lives in the repo, not in the database. **Before any report is
opened in an environment, check that the published Privacy Policy page in that
environment actually carries §4.5b** — read the live page, not the markdown. To
republish it: `php artisan content:publish privacy`. Do **not** use
`db:seed --class=ContentPageSeeder` for this: it publishes four policy pages at
once, and it would overwrite a privacy page somebody has since edited.

Reporting therefore **ships OFF**. *Let distributors report a message* is at its
default (off) until somebody turns it on for that environment, which is an
audited settings edit: read the live Privacy Policy page first, confirm §4.5b is
on it, then switch it on. Until then the report button is not shown and the
report endpoint 404s.

Two things are still open, tracked as **R-80**:

1. If the Privacy Notice was already published to distributors without §4.5b,
   the 30-day material-change notice in §13 has to run before reporting goes
   live. It is a settings toggle (*Let distributors report a message*), not a
   deploy — turn it off until the notice has run.
2. Deleting a user row would cascade away their messages and any report filed
   against them. Nothing does that today (accounts are terminated, not deleted),
   but the legal hold is documented rather than enforced in the schema. Do not
   honour an erasure request by deleting a user until that is fixed.

---

## 2. Company announcements

**Admin → Announcements**, behind the *Company announcements* flag.

An announcement is company copy addressed to distributors — the only copy on
the platform that reaches everyone at once without passing through a code
review. It is therefore held to the same rule as the public site: nothing that
implies a future income (DSR 2021 Rule 5(1)(d); Code IV.XII.a).

### Writing one

Saving and publishing are separate steps on purpose. Write it, read it back,
then publish.

The wording is checked **on save and again on publish**, and a phrase that
breaks the rule is refused rather than flagged — a warning on a page someone is
trying to publish is a warning someone clicks past. The re-check at publish
exists because a draft can be saved clean and edited in another tab.

Say what has happened. Never what someone might earn.

### Audience

- **Every distributor.**
- **Accounts in one state** — pending, active, blocked, terminated, rejected.
- **A rank and above** — anyone who has *ever* qualified at that rank or
  higher. Deliberately "has ever reached", not "holds this month": an audience
  that changed shape on every engine run would mean two people opening the same
  page see different lists.

Membership is worked out when a distributor loads the page, not frozen when you
publish. Someone who activates the day after a "for active accounts"
announcement goes out will see it.

### Pinning, expiry, archiving

- **Pinned** announcements sit at the top. The limit is a setting (default 3).
  Pinning past the limit is refused rather than silently unpinning someone
  else's.
- **Stop showing it after** sets an expiry. Leave it blank to keep it up until
  someone archives it.
- **Archiving** is the closest thing to unsending there is, and it only reaches
  the in-app copy.

### The email copy

**Settings → Also email each announcement** (default OFF). When on, publishing
also emails the announcement to everyone in its audience, and the confirmation
tells you how many people it went to.

An emailed announcement cannot be withdrawn. The in-app copy can be archived;
an email that has gone out is out. Only the first publish emails — a republish
after an archive does not mail the same people twice.

---

## 3. FAQ library

**Content → new page, type `faq`**, behind the *FAQ library* flag.

FAQ entries are ordinary content pages, so they carry the same draft →
published workflow and the same audit trail as the policy pages. On top of that
they are the one content type whose **title and body are refused at save if
they carry an income projection** — the same phrase list that guards
announcements and that the public copy audit reads. That rule is scoped to FAQ
entries on purpose: the Code of Ethics page quotes those phrases in order to
forbid them, so a blanket rule would make the Code of Ethics uneditable. What
FAQ entries also add is a **category** and a **sort order**, because a library
of forty answers in publication order is not a library. Leave the sort order
blank and it saves as 0 — "no particular order", which sorts alphabetically
within its category.

FAQ entries never appear at `/p/{slug}` — that reader serves the policy pages,
the blog, news and seminars. An FAQ answer is reachable only through `/faq`,
which is what the flag and the members-only setting actually gate.

The library exists so that plan, payout and KYC questions have one audited
company answer. Today they are answered by uplines in direct messages, where
nothing scans the wording for an income claim.

**Settings → FAQ library is members-only** (default ON). Turning it off makes
the library a public page indexed by search engines — at which point every
answer is a statement the company has published to prospects, and the same
income-representation rules apply to it as to the landing page.

While the flag is on, the content editor offers **faq — FAQ answer** in the
Content Type list and shows two extra fields, **FAQ category** and **Sort
order**. Moving an entry back to *(blank) — General* takes it out of the library
and publishes it as an ordinary page at `/p/{slug}`, so that save is checked for
an income projection like any other.

While the flag is off, `faq` is refused as a content-page type in the editor:
a draft authored against a surface nobody can reach is a draft that gets
forgotten and published later by accident.
