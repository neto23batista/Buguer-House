CREATE TABLE IF NOT EXISTS order_status_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    previous_status ENUM('received', 'preparing', 'ready', 'completed', 'cancelled') NOT NULL,
    next_status ENUM('received', 'preparing', 'ready', 'completed', 'cancelled') NOT NULL,
    actor VARCHAR(255) NOT NULL,
    reason VARCHAR(240) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    INDEX idx_order_status_events_order (order_id, id),
    CONSTRAINT fk_order_status_events_order FOREIGN KEY (order_id) REFERENCES orders (id)
) ENGINE = InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
