CREATE TABLE sponsorship (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sponsor_id     BIGINT UNSIGNED NOT NULL,
    distributor_id BIGINT UNSIGNED NOT NULL,
    created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uniq_sponsorship_distributor (distributor_id),
    KEY idx_sponsorship_sponsor (sponsor_id),
    CONSTRAINT fk_sponsorship_sponsor     FOREIGN KEY (sponsor_id)     REFERENCES distributors(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sponsorship_distributor FOREIGN KEY (distributor_id) REFERENCES distributors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
