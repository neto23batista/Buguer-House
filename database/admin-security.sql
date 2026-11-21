CREATE TABLE IF NOT EXISTS admin_login_attempts (
    bucket VARCHAR(72) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    INDEX idx_admin_login_expires_at (expires_at)
) ENGINE = InnoDB;
