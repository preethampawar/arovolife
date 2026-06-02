# Domain Events Catalog — Phase 1

Every non-trivial state change emits a domain event. Events are
dispatched via Laravel's event bus and MAY be handled synchronously
(within the request) or via queue (preferred for side-effects).

## Naming convention

`<bounded-context>.<entity>.<verb-past-tense>`

Examples: `identity.user.registered`, `genealogy.placement.created`,
`compliance.cooling_off.cancelled`.

## Phase 1 events

### `identity.user.registered`
- When: a new user account is created at the start of registration.
- Payload: `user_id`, `email`, `phone_e164`, `ip`, `ua`, `sponsor_id`, `source`.
- Handlers: welcome-email, analytics sink, audit-log writer.

### `identity.user.mfa_enabled`
- When: a user completes TOTP enrolment.
- Payload: `user_id`, `enabled_at`.
- Handlers: audit-log, dashboard badge.

### `kyc.pan.verified`
- When: PAN auto-verification succeeds.
- Payload: `distributor_id`, `pan_last4`, `provider`, `provider_ref`.
- Handlers: distributor state machine, audit-log.

### `kyc.aadhaar.verified`
- When: Aadhaar OTP verification succeeds.
- Payload: `distributor_id`, `aadhaar_last4`, `aadhaar_ref`, `provider`.
- Handlers: distributor state machine, audit-log.

### `kyc.bank.verified`
- When: bank penny-drop succeeds.
- Payload: `distributor_id`, `bank_last4`, `ifsc`, `provider_ref`.

### `orientation.view.completed`
- When: watch ≥ 95% AND quiz passed.
- Payload: `distributor_id`, `video_id`, `watch_percent`, `quiz_score`.

### `consent.accepted`
- When: a user accepts a versioned legal doc.
- Payload: `distributor_id`, `document_type`, `document_version`, `doc_hash`.

### `genealogy.distributor.registered`
- When: a distributor is fully activated (placement + ADN issued).
- Payload: `distributor_id`, `adn`, `sponsor_id`, `placement_id_at_registration`, `effective_date`, `cooling_off_end_at`.
- Handlers: send welcome email with ADN, seed dashboard, analytics.

### `genealogy.placement.created`
- When: a distributor is placed in the Genos (binary placement tree).
- Payload: `distributor_id`, `sponsor_id`, `placement_id`, `placement_parent_id`, `placement_side`, `depth`, `strategy_snapshot`, `side_chosen_by`.
- Handlers: closure-table writer (synchronous, inside tx), analytics, audit-log.

### `genealogy.line_change.requested`
- Payload: `distributor_id`, `from_sponsor_id`, `to_sponsor_id`, `requested_at`.

### `genealogy.line_change.approved` / `.rejected`
- Payload: `request_id`, `approved_by`, `decided_at`.

### `compliance.cooling_off.opened`
- When: a distributor is created; fires immediately after `genealogy.distributor.registered`.

### `compliance.cooling_off.reminder_sent`
- Payload: `distributor_id`, `milestone` (20 | 7 | 1).

### `compliance.cooling_off.cancelled`
- Payload: `distributor_id`, `cancelled_at`, `refund_event_ref`.
- Handlers: freeze ADN, queue refund (Phase 3+), audit-log.

### `admin.settings.changed`
- When: an admin updates a `settings` row (e.g., Placement Strategy).
- Payload: `key`, `before`, `after`, `version`, `actor_id`, `reason`, `ip`.
- Handlers: audit-log, SRE notification channel.

### `admin.distributor.frozen` / `.unfrozen` / `.terminated`
- Payload: `distributor_id`, `reason`, `actor_id`, `at`.

## Phase 2 events (Commerce)

### `commerce.order.placed` (`App\Modules\Commerce\Events\OrderPlaced`)
- When: an order is successfully placed (dispatched after the placement
  transaction commits, from `CheckoutService::place()`).
- Payload: `orderId`.
- Handlers: `SendOrderPlacedMail` (queued) → channel-agnostic
  `OrderPlacedNotification` (mail now; SMS when the gateway lands). Always sent.

### `commerce.order.status_changed` (`App\Modules\Commerce\Events\OrderStatusChanged`)
- When: an order transitions state (paid, shipped, delivered, confirmed,
  cancelled), from `OrderStateMachine`. Dispatched after each transition.
- Payload: `orderId`, `oldStatus`, `newStatus`.
- Handlers: `SendOrderStatusChangedMail` (queued), gated by the admin setting
  `notifications.email_on_status_change` (default on).

## Idempotency

Every event MUST be reproducible from its payload — handlers that are
not idempotent must enforce idempotency via a unique key derived from
the payload (`event_id = sha256(type|payload|timestamp)` is a reasonable default).
