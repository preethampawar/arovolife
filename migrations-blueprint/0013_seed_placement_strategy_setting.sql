-- Seeder reference (prefer a Laravel seeder in code).
-- Seeds the default Placement Strategy.
-- Values come from PO decisions D-01 and D-02 in the PRD.

INSERT INTO settings (`key`, value, version, updated_by, created_at, updated_at)
VALUES
    ('placement.default_side',             'default_left', 1, NULL, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3)),
    ('placement.allow_sponsor_override',   'true',         1, NULL, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3));

-- Reminder: every UPDATE to these rows must also write an `audit_log`
-- row with action='admin.settings.changed' and details JSON containing
-- {key, before, after, version, reason, ip}.
