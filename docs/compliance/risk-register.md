# Compliance Risk Register

Standing risks re-assessed at every phase exit gate. Critical / High
risks must be mitigated or formally accepted (dated, signed) before a
phase can close.

| ID | Risk | Nature | Source | Mitigation | Phase owner | Status |
|---|---|---|---|---|---|---|
| R-01 | Pyramid / money-circulation interpretation | Statutory — Critical | DSR 2021 §2(1)(j); T&C Definition "Pyramid Scheme" | Commissions only on product sales; every commission row has `product_sale_id NOT NULL` | 4 | Pending |
| R-02 | Mis-selling & income guarantees | Statutory — High | DSR 5(1)(d); T&C §6 | No earnings calculator on public site; orientation required; all marketing copy reviewed | All | In-design |
| R-03 | Forced purchase at joining | Statutory — High | T&C §4 | Registration cannot add SKUs; unit test asserts | 1 | In-progress |
| R-04 | Failure of cooling-off (30 days) | Statutory — High | T&C §4, Code V | `cooling_off_end_at` stamp; one-click cancel; reminders at D-20 / D-7 / D-1 | 1 + 8 | In-progress |
| R-05 | Buyback / return not honoured | Statutory — Medium | T&C §8 | Workflow enforces saleable/non-saleable split and GST deduction rules | 8 | Scheduled |
| R-06 | Duplicate PAN / duplicate ADN | Statutory — High | T&C §1.4, §7 | Unique index on PAN hash; couple rule engine; duplicate detection cron | 1 | In-progress |
| R-07 | KYC data breach (PAN, Aadhaar) | Data protection — Critical | DPDP 2023; T&C §15 | AES-256 at rest; last-4 display; audit log; purpose-limited access | 1 | In-progress |
| R-08 | Third-party resale / e-commerce listing | Contractual — High | T&C §9 | Terms acceptance; marketplace crawler; takedown workflow | 2 + 8 | Scheduled |
| R-09 | Minor as distributor | Statutory — Critical | T&C §1.1 | DOB validation with state threshold; PAN cross-verification | 1 | In-progress |
| R-10 | Auto-termination of inactive DS (>1 y) | Contractual — Medium | T&C §21 | Scheduled cron on `last_sale_at`; 7-day notice; freeze then terminate | 8 | Scheduled |
| R-11 | Grievance redressal SLA breach | Statutory — Medium | T&C §11 | Ticket ID + SLA clock; status updates; public status page | 8 | Scheduled |
| R-12 | Placement Strategy flipped mid-flow | Operational — High | ADR-0002 (superseded) | n/a — ADR-0003 removes the strategy concept entirely; no `placement_strategy_snapshot` column, no resolver; placement is a single-level invariant rule | 1 | Mitigated (2026-05-01) |
| R-13 | Placement_id outside sponsor's downline | Operational — High | ADR-0003 | `PlacementEngine::isSelfOrDescendant()` (`app/Modules/Genealogy/Services/PlacementEngine.php:147`); cross-line attempts raise `CrossLinePlacementError`, dispatch `ForbiddenPlacementAttempted`, write `genealogy.placement.rejected` audit row; `RegistrationWizardController::start()` re-validates at link-open time and surfaces failures as a generic Contact Us redirect | 1 | Mitigated (2026-05-01) |
| R-14 | Contact-form PII on a plaintext queue payload | Data protection — Medium | DPDP 2023 §6, §8(3) | `NewContactInquiryNotification` is constructed with the inquiry id only; PII is re-fetched at delivery time so the `jobs` table never carries name/email/phone/address/message | 1 | Mitigated (2026-05-01) |
| R-15 | Contact-inquiry retention not bounded | Data protection — Medium | DPDP 2023 §8(3) | `contact-inquiries:purge` artisan command (`app/Modules/Public/Console/PurgeStaleContactInquiriesCommand.php`) deletes unhandled inquiries >90d and handled inquiries >365d; scheduled daily at 03:00 IST via `PublicServiceProvider::boot()`; each run records counts (no PII) in `audit_log`; covered by 6 Pest tests (PURGE-01..06) | 1 | Mitigated (2026-05-01) |

## Compliance items C-01 … C-09 (Phase 1)

Aligned to the PRD §12. Each must be signed before Phase 1 exits.

| ID | Item | Signed by | Signed date | Artefact |
|---|---|---|---|---|
| C-01 | Joining free of cost enforced in code | | | Test + PR link |
| C-02 | No income projection in registration UI | | | Content review memo |
| C-03 | Orientation gate enforced | | | Test + PR link |
| C-04 | Cooling-off one-click cancellation | | | Test + PR link |
| C-05 | Register of DS export working, retention policy set | | | Admin export screenshot + retention ADR |
| C-06 | DPDP: consent is purpose-limited, revocable, DF contact published | | | Privacy notice + consent table migration |
| C-07 | State-aware age rule honoured (MH=21) | | | Test + PR link |
| C-08 | Declarations (jail/bankrupt/sound-mind) captured | | | Test + PR link |
| C-09 | Placement transparency — snapshot + resolved side visible to DS | | | Test + screenshot |

## Accepted risks (dated)

_None yet. Entries go here with: date, acceptor names, risk ID, reason,
review date._
