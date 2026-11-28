CREATE TABLE IF NOT EXISTS payment_method_changes (
    order_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    previous_method ENUM('cash','pix','card') NOT NULL,
    new_method ENUM('cash','pix','card') NOT NULL,
    reason VARCHAR(240) NOT NULL,
    actor VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    PRIMARY KEY (order_id, revision),
    FOREIGN KEY (order_id) REFERENCES payments(order_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
