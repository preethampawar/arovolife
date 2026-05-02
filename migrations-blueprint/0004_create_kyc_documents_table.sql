CREATE TABLE kyc_documents (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    distributor_id     BIGINT UNSIGNED NOT NULL,
    type               ENUM('pan','aadhaar','cheque','address_proof_front','address_proof_back','photo') NOT NULL,
    object_storage_key VARCHAR(512) NOT NULL,
    checksum_sha256    BINARY(32) NOT NULL,
    verified_at        DATETIME(3) NULL,
    verifier_id        BIGINT UNSIGNED NULL,
    created_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_kyc_distributor (distributor_id),
    CONSTRAINT fk_kyc_distributor FOREIGN KEY (distributor_id) REFERENCES distributors(id) ON DELETE CASCADE,
    CONSTRAINT fk_kyc_verifier    FOREIGN KEY (verifier_id)    REFERENCES users(id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
