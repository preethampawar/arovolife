CREATE TABLE agreements (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    type           ENUM('tnc','ethics','plan','privacy') NOT NULL,
    version        VARCHAR(32) NOT NULL,
    pdf_hash       BINARY(32)  NOT NULL,
    effective_from DATETIME(3) NOT NULL,
    supersedes_id  BIGINT UNSIGNED NULL,
    created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uniq_agreements_type_version (type, version),
    CONSTRAINT fk_agreements_supersedes FOREIGN KEY (supersedes_id) REFERENCES agreements(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
