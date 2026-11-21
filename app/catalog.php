<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

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
