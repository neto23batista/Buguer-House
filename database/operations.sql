CREATE TABLE IF NOT EXISTS product_photos (
    digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    jpeg MEDIUMBLOB NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS staff_accounts (
    username VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    password_hash VARCHAR(255) CHARACTER SET ascii NOT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    active BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cash_days (
    business_date DATE PRIMARY KEY,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    counted_cents BIGINT UNSIGNED NULL,
    closed_by VARCHAR(255) NULL,
    closed_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS payments (
    order_id BIGINT UNSIGNED PRIMARY KEY,
    business_date DATE NOT NULL,
    method ENUM('cash','pix','card') NOT NULL,
    amount_cents BIGINT UNSIGNED NOT NULL,
    received_by VARCHAR(255) NOT NULL,
    received_at DATETIME NOT NULL,
    INDEX idx_payment_day (business_date,method),
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (business_date) REFERENCES cash_days(business_date)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS cash_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_date DATE NOT NULL,
    action ENUM('close','reopen') NOT NULL,
    actor VARCHAR(255) NOT NULL,
    counted_cents BIGINT UNSIGNED NOT NULL,
    expected_cents BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    INDEX idx_cash_events_day (business_date,id),
    FOREIGN KEY (business_date) REFERENCES cash_days(business_date)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
