CREATE TABLE audit_log (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_id     BIGINT UNSIGNED NULL,
    action       VARCHAR(128) NOT NULL,
    subject_type VARCHAR(128) NOT NULL,
    subject_id   BIGINT UNSIGNED NULL,
    before_hash  BINARY(32) NULL,
    after_hash   BINARY(32) NULL,
    details      JSON NULL,
    ip           VARCHAR(64) NULL,
    created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_audit_subject     (subject_type, subject_id),
    KEY idx_audit_action_time (action, created_at),
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
