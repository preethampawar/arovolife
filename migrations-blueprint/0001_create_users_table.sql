CREATE TABLE users (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email           VARCHAR(255) NOT NULL,
    phone_e164      VARCHAR(16)  NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    mfa_secret_enc  VARBINARY(512) NULL,
    mfa_enabled_at  DATETIME(3) NULL,
    status          ENUM('pending','active','frozen','terminated') NOT NULL DEFAULT 'pending',
    last_login_at   DATETIME(3) NULL,
    remember_token  VARCHAR(100) NULL,
    email_verified_at DATETIME(3) NULL,
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uniq_users_email (email),
    UNIQUE KEY uniq_users_phone (phone_e164)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
