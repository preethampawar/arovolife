# Phase 1 — Data Model Reference

Canonical schema for Phase 1 tables. Every change here requires a
migration and an updated model. Migrations blueprints live in
`migrations-blueprint/`.

## Bounded contexts and their tables

| Module | Tables |
|---|---|
| Identity | `users`, `roles`, `permissions`, `role_user`, `password_resets`, `personal_access_tokens` |
| Genealogy | `distributors`, `genealogy_closure`, `sponsorship`, `line_change_requests` |
| Kyc | `kyc_documents` |
| Consent | `consents`, `agreements` |
| Orientation | `orientation_views` |
| Compliance | `cooling_off_events` |
| Admin / Shared | `settings`, `audit_log` |

## Key tables (abridged)

### `users`
```
id              BIGINT UNSIGNED PK AUTO_INC
email           VARCHAR(255) UNIQUE NOT NULL
phone_e164      VARCHAR(16)  UNIQUE NOT NULL
password_hash   VARCHAR(255) NOT NULL           -- bcrypt cost 12
mfa_secret_enc  VARBINARY(512) NULL             -- encrypted TOTP secret
mfa_enabled_at  DATETIME NULL
status          ENUM('pending','active','frozen','terminated') NOT NULL DEFAULT 'pending'
last_login_at   DATETIME NULL
created_at      DATETIME
updated_at      DATETIME
```

### `distributors`
```
id                              BIGINT UNSIGNED PK
user_id                         BIGINT UNSIGNED UNIQUE NOT NULL  → users.id
adn                             VARCHAR(16) UNIQUE NOT NULL      -- Arovolife Distributor Number
pan_hash                        BINARY(32) UNIQUE NOT NULL       -- sha-256 of canonicalised PAN
pan_last4                       CHAR(4) NOT NULL
aadhaar_ref                     VARCHAR(64) NULL                 -- returned by UIDAI-approved partner
aadhaar_last4                   CHAR(4) NULL
bank_account_enc                VARBINARY(512) NOT NULL          -- encrypted at rest
bank_ifsc                       CHAR(11) NOT NULL
sponsor_id                      BIGINT UNSIGNED NOT NULL         → distributors.id
placement_id_at_registration    BIGINT UNSIGNED NULL             → distributors.id (nullable ⇒ defaulted to sponsor)
placement_parent_id             BIGINT UNSIGNED NOT NULL         → distributors.id
placement_side                  ENUM('L','R') NOT NULL
placement_strategy_snapshot     ENUM('default_left','default_right','custom') NOT NULL
side_chosen_by                  ENUM('admin_default','sponsor_override','prospect_custom') NOT NULL
depth                           INT UNSIGNED NOT NULL
effective_date                  DATETIME NOT NULL
cooling_off_end_at              DATETIME NOT NULL
state                           VARCHAR(64) NOT NULL             -- for state-aware age rule
spouse_distributor_id           BIGINT UNSIGNED NULL             → distributors.id
is_primary_couple               TINYINT(1) NOT NULL DEFAULT 0
created_at                      DATETIME
updated_at                      DATETIME
UNIQUE KEY uniq_slot (placement_parent_id, placement_side)
INDEX idx_sponsor (sponsor_id)
INDEX idx_state_status (state)
```

### `settings`
```
id          BIGINT UNSIGNED PK
key         VARCHAR(128) UNIQUE NOT NULL         -- 'placement.default_side', 'placement.allow_sponsor_override', ...
value       VARCHAR(512) NOT NULL
version     INT UNSIGNED NOT NULL DEFAULT 1
updated_by  BIGINT UNSIGNED NULL → users.id
updated_at  DATETIME
created_at  DATETIME
```

### `genealogy_closure`
```
ancestor_id   BIGINT UNSIGNED NOT NULL → distributors.id
descendant_id BIGINT UNSIGNED NOT NULL → distributors.id
depth         INT UNSIGNED NOT NULL
PRIMARY KEY (ancestor_id, descendant_id)
INDEX idx_desc (descendant_id)
INDEX idx_anc_depth (ancestor_id, depth)
```

### `sponsorship`
```
id           BIGINT UNSIGNED PK
sponsor_id   BIGINT UNSIGNED NOT NULL → distributors.id
distributor_id BIGINT UNSIGNED UNIQUE NOT NULL → distributors.id
created_at   DATETIME
INDEX idx_sponsor (sponsor_id)
```

### `kyc_documents`
```
id                 BIGINT UNSIGNED PK
distributor_id     BIGINT UNSIGNED NOT NULL → distributors.id
type               ENUM('pan','aadhaar','cheque','address_proof_front','address_proof_back','photo') NOT NULL
object_storage_key VARCHAR(512) NOT NULL
checksum_sha256    BINARY(32) NOT NULL
verified_at        DATETIME NULL
verifier_id        BIGINT UNSIGNED NULL → users.id
created_at         DATETIME
updated_at         DATETIME
INDEX idx_distributor (distributor_id)
```

### `consents`
```
id               BIGINT UNSIGNED PK
distributor_id   BIGINT UNSIGNED NOT NULL → distributors.id
document_type    ENUM('tnc','ethics','plan','privacy') NOT NULL
document_version VARCHAR(32) NOT NULL
doc_hash_sha256  BINARY(32) NOT NULL
accepted_at      DATETIME NOT NULL
ip               VARCHAR(64) NOT NULL
user_agent       VARCHAR(512) NOT NULL
INDEX idx_distributor (distributor_id)
```

### `orientation_views`
```
id                    BIGINT UNSIGNED PK
distributor_id        BIGINT UNSIGNED NOT NULL → distributors.id
video_id              VARCHAR(64) NOT NULL
started_at            DATETIME NOT NULL
completed_at          DATETIME NULL
watch_percent         INT UNSIGNED NOT NULL DEFAULT 0
quiz_passed_at        DATETIME NULL
playback_fingerprint  VARCHAR(128) NULL
```

### `line_change_requests`
```
id               BIGINT UNSIGNED PK
distributor_id   BIGINT UNSIGNED NOT NULL → distributors.id
from_sponsor_id  BIGINT UNSIGNED NOT NULL → distributors.id
to_sponsor_id    BIGINT UNSIGNED NOT NULL → distributors.id
requested_at     DATETIME NOT NULL
approved_at      DATETIME NULL
status           ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending'
reason           VARCHAR(512) NULL
```

### `cooling_off_events`
```
id                     BIGINT UNSIGNED PK
distributor_id         BIGINT UNSIGNED NOT NULL → distributors.id
opened_at              DATETIME NOT NULL
cancelled_at           DATETIME NULL
refund_trigger_event_id VARCHAR(64) NULL
```

### `audit_log`
```
id          BIGINT UNSIGNED PK
actor_id    BIGINT UNSIGNED NULL → users.id
action      VARCHAR(128) NOT NULL
subject_type VARCHAR(128) NOT NULL
subject_id  BIGINT UNSIGNED NULL
before_hash BINARY(32) NULL
after_hash  BINARY(32) NULL
details     JSON NULL
ip          VARCHAR(64) NULL
created_at  DATETIME NOT NULL
INDEX idx_subject (subject_type, subject_id)
INDEX idx_action_time (action, created_at)
```

### `agreements`
```
id             BIGINT UNSIGNED PK
type           ENUM('tnc','ethics','plan','privacy') NOT NULL
version        VARCHAR(32) NOT NULL
pdf_hash       BINARY(32) NOT NULL
effective_from DATETIME NOT NULL
supersedes_id  BIGINT UNSIGNED NULL → agreements.id
```

## Retention

- KYC: 8 years after termination (DSR + Income Tax).
- Audit log: 8 years with tamper-evident hash chain.
- Orientation views: 8 years.
- Consents: 8 years with the document hash preserved.
- Cooling-off events: 8 years.

## Encryption

- `pan_hash`: SHA-256; input canonicalised (uppercase, trimmed).
- `aadhaar_ref`, `aadhaar_last4`: stored; raw Aadhaar NEVER stored.
- `bank_account_enc`, `mfa_secret_enc`: Laravel encrypter with a key
  whose id is tracked in an env var (`KYC_ENCRYPTION_KEY_ID`) so
  rotation is possible.
- All other PII: TLS in transit; Lightsail-managed disk encryption at rest.
