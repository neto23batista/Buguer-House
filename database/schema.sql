SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NOT NULL,
    category ENUM('burgers', 'acompanhamentos', 'bebidas') NOT NULL,
    price_cents INT UNSIGNED NOT NULL,
    image VARCHAR(120) NOT NULL,
    image_alt VARCHAR(180) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    INDEX idx_products_menu (is_active, sort_order, id)
) ENGINE = InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

INSERT INTO products (slug, name, description, category, price_cents, image, image_alt, sort_order)
VALUES
    ('house-smash', 'House Smash', 'Smash bovino, cheddar, picles e molho da casa no pão brioche.', 'burgers', 2890, 'house-smash.webp', 'Hambúrguer com cheddar derretido e picles no pão brioche', 1),
    ('bacon-melt', 'Bacon Melt', 'Smash bovino, cheddar derretido e bacon caramelizado no pão brioche.', 'burgers', 3490, 'bacon-melt.webp', 'Hambúrguer com cheddar e fatias de bacon no pão brioche', 2),
    ('double-stack', 'Double Stack', 'Dois discos de carne na chapa, cheddar, picles e molho da casa no pão brioche.', 'burgers', 3890, 'double-stack.webp', 'Hambúrguer duplo com cheddar derretido no pão brioche', 3),
    ('fritas-da-casa', 'Fritas da Casa', 'Batatas douradas e crocantes, finalizadas com sal e ervas.', 'acompanhamentos', 1490, 'fritas.webp', 'Porção de batatas fritas douradas com ervas', 4),
    ('shake-de-baunilha', 'Shake de Baunilha', 'Milk-shake cremoso de baunilha, batido na hora.', 'bebidas', 1890, 'shake.webp', 'Milk-shake de baunilha em copo transparente', 5)
ON DUPLICATE KEY UPDATE slug = products.slug;

CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_code CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    checkout_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    customer_name VARCHAR(100) NOT NULL,
    customer_phone VARCHAR(11) CHARACTER SET ascii NOT NULL,
    notes VARCHAR(300) NOT NULL DEFAULT '',
    total_cents BIGINT UNSIGNED NOT NULL,
    status ENUM('received', 'preparing', 'ready', 'completed', 'cancelled') NOT NULL DEFAULT 'received',
    created_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    updated_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    UNIQUE KEY uq_orders_public_code (public_code),
    UNIQUE KEY uq_orders_checkout_token (checkout_token),
    INDEX idx_orders_status (status, id)
) ENGINE = InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
    order_id BIGINT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    product_name VARCHAR(100) NOT NULL,
    unit_price_cents INT UNSIGNED NOT NULL,
    quantity TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (order_id, product_id),
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders (id),
    CONSTRAINT chk_order_items_quantity CHECK (quantity BETWEEN 1 AND 99)
) ENGINE = InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
