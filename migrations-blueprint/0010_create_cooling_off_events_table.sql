CREATE TABLE cooling_off_events (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    distributor_id          BIGINT UNSIGNED NOT NULL,
    opened_at               DATETIME(3) NOT NULL,
    cancelled_at            DATETIME(3) NULL,
    refund_trigger_event_id VARCHAR(64) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_cooling_off_distributor (distributor_id),
    CONSTRAINT fk_cooling_off_distributor FOREIGN KEY (distributor_id) REFERENCES distributors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
