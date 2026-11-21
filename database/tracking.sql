CREATE TABLE IF NOT EXISTS order_tracking (
    order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    pickup_at DATETIME NOT NULL,
    pickup_address VARCHAR(250) NOT NULL DEFAULT '',
    CONSTRAINT fk_order_tracking_order FOREIGN KEY (order_id) REFERENCES orders (id)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
