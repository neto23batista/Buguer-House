<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

const CATALOG_PRODUCT_FIELDS = 'id, slug, name, description, category, price_cents, image, image_alt';

function loadProducts(?PDO $connection = null): array
{
    $rows = ($connection ?? databaseConnection())->query(
        'SELECT ' . CATALOG_PRODUCT_FIELDS . '
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

/** Changes to the product the customer reviewed require a new checkout confirmation. */
function productCatalogSignature(array $product): string
{
    return hash('sha256', json_encode([
        $product['slug'], $product['name'], $product['description'], $product['category'],
        (int) $product['price_cents'], $product['image'], $product['image_alt'],
    ], JSON_THROW_ON_ERROR));
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
