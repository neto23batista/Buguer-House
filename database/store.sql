CREATE TABLE IF NOT EXISTS store_settings (
    id TINYINT UNSIGNED PRIMARY KEY,
    pickup_address VARCHAR(250) NOT NULL DEFAULT '',
    prep_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 25,
    paused BOOLEAN NOT NULL DEFAULT TRUE,
    schedule JSON NOT NULL,
    exceptions JSON NOT NULL,
    CONSTRAINT chk_store_settings_singleton CHECK (id = 1),
    CONSTRAINT chk_store_settings_prep CHECK (prep_minutes BETWEEN 5 AND 240)
) ENGINE = InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

INSERT INTO store_settings (id, paused, schedule, exceptions)
VALUES (
    1,
    TRUE,
    JSON_OBJECT(
        '1', JSON_ARRAY(),
        '2', JSON_ARRAY(),
        '3', JSON_ARRAY(),
        '4', JSON_ARRAY(),
        '5', JSON_ARRAY(),
        '6', JSON_ARRAY(),
        '7', JSON_ARRAY()
    ),
    JSON_OBJECT()
)
ON DUPLICATE KEY UPDATE id = store_settings.id;
