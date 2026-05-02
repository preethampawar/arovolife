CREATE TABLE settings (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key`      VARCHAR(128) NOT NULL,
    value      VARCHAR(512) NOT NULL,
    version    INT UNSIGNED NOT NULL DEFAULT 1,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uniq_settings_key (`key`),
    CONSTRAINT fk_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NB: Default values for `placement.default_side` and
-- `placement.allow_sponsor_override` are inserted by the Laravel seeder
-- SettingsSeeder (see migrations-blueprint/0013_seed_placement_strategy_setting.sql).
