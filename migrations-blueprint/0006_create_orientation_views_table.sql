CREATE TABLE orientation_views (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    distributor_id        BIGINT UNSIGNED NOT NULL,
    video_id              VARCHAR(64) NOT NULL,
    started_at            DATETIME(3) NOT NULL,
    completed_at          DATETIME(3) NULL,
    watch_percent         INT UNSIGNED NOT NULL DEFAULT 0,
    quiz_passed_at        DATETIME(3) NULL,
    playback_fingerprint  VARCHAR(128) NULL,
    PRIMARY KEY (id),
    KEY idx_orientation_distributor (distributor_id),
    CONSTRAINT fk_orientation_distributor FOREIGN KEY (distributor_id) REFERENCES distributors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
