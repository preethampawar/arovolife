CREATE TABLE line_change_requests (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    distributor_id   BIGINT UNSIGNED NOT NULL,
    from_sponsor_id  BIGINT UNSIGNED NOT NULL,
    to_sponsor_id    BIGINT UNSIGNED NOT NULL,
    requested_at     DATETIME(3) NOT NULL,
    approved_at      DATETIME(3) NULL,
    status           ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
    reason           VARCHAR(512) NULL,
    PRIMARY KEY (id),
    KEY idx_line_change_distributor (distributor_id),
    CONSTRAINT fk_lcr_distributor FOREIGN KEY (distributor_id)  REFERENCES distributors(id) ON DELETE CASCADE,
    CONSTRAINT fk_lcr_from        FOREIGN KEY (from_sponsor_id) REFERENCES distributors(id) ON DELETE RESTRICT,
    CONSTRAINT fk_lcr_to          FOREIGN KEY (to_sponsor_id)   REFERENCES distributors(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
