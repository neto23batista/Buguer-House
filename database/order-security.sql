CREATE TABLE IF NOT EXISTS order_rate_limits (
    source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    order_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    INDEX idx_order_rate_expiry (expires_at)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Legacy tokens are 64 hex characters. Their URL-safe SHA-256 digests are 43 characters.
UPDATE orders
SET checkout_token = REPLACE(REPLACE(REPLACE(TO_BASE64(UNHEX(SHA2(checkout_token, 256))), '+', '-'), '/', '_'), '=', '')
WHERE CHAR_LENGTH(checkout_token) = 64;
