<?php

declare(strict_types=1);

function databaseSetting(string $key, string $default): string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function databaseConnection(): PDO
{
    $host = databaseSetting('DB_HOST', '127.0.0.1');
    $port = databaseSetting('DB_PORT', '3306');
    $database = databaseSetting('DB_NAME', 'burger_house');

    $connection = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        databaseSetting('DB_USER', 'bh_web'),
        databaseSetting('DB_PASSWORD', ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
    $connection->exec("SET time_zone = '+00:00'");
    return $connection;
}

function loadProducts(?PDO $connection = null): array
{
    $rows = ($connection ?? databaseConnection())->query(
        'SELECT id, slug, name, description, category, price_cents, image, image_alt
         FROM products WHERE is_active = 1 ORDER BY sort_order, id',
    );

    $products = [];
    foreach ($rows as $row) {
        $row['id'] = (int) $row['id'];
        $row['price_cents'] = (int) $row['price_cents'];
        $products[$row['id']] = $row;
    }

    return $products;
}

function escape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function price(int $cents): string
{
    $reais = number_format(intdiv($cents, 100), 0, ',', '.');
    $centavos = str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    return "R$ {$reais},{$centavos}";
}

function productImage(array $product): string
{
    return 'assets/img/' . rawurlencode($product['image']);
}

function productCategories(): array
{
    return ['burgers' => 'Burgers', 'acompanhamentos' => 'Acompanhamentos', 'bebidas' => 'Bebidas'];
}
