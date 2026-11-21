CREATE TABLE IF NOT EXISTS web_sessions (
    session_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    payload MEDIUMBLOB NOT NULL,
    expires_at DATETIME NOT NULL,
    INDEX idx_web_sessions_expiry (expires_at)
) ENGINE = InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
