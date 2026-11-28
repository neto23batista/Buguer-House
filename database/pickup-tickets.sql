CREATE TABLE IF NOT EXISTS pickup_sequences (
    pickup_day DATE PRIMARY KEY,
    last_number INT UNSIGNED NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pickup_tickets (
    order_id BIGINT UNSIGNED PRIMARY KEY,
    pickup_day DATE NOT NULL,
    ticket_number INT UNSIGNED NOT NULL,
    UNIQUE KEY uq_pickup_day_number (pickup_day,ticket_number),
    FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB;
