CREATE TABLE consents (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    distributor_id   BIGINT UNSIGNED NOT NULL,
    document_type    ENUM('tnc','ethics','plan','privacy') NOT NULL,
    document_version VARCHAR(32) NOT NULL,
    doc_hash_sha256  BINARY(32)  NOT NULL,
    accepted_at      DATETIME(3) NOT NULL,
    ip               VARCHAR(64)  NOT NULL,
    user_agent       VARCHAR(512) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_consents_distributor (distributor_id),
    CONSTRAINT fk_consents_distributor FOREIGN KEY (distributor_id) REFERENCES distributors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
