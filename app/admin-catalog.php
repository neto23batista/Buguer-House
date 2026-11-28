<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/product-photo.php';

function adminProductImages(): array
{
    $directory = __DIR__ . '/../public/assets/img';
    if (!is_dir($directory) || !is_readable($directory)) {
        return [];
    }
    $images = [];
    foreach (new DirectoryIterator($directory) as $file) {
        $name = $file->getFilename();
        if (!$file->isFile() || $file->isLink() || strcasecmp($name, 'hero.webp') === 0) {
            continue;
        }
        if (strlen($name) <= 120 && preg_match('/\A(?!\.)[^\x00-\x1f\x7f\/\\\\]+\.(?:avif|jpe?g|png|webp)\z/iu', $name)) {
            $images[] = $name;
        }
    }
    natcasesort($images);
    return array_values($images);
}

function adminProductDefaults(): array
{
    return [
        'name' => '',
        'description' => '',
        'category' => 'burgers',
        'price' => '',
        'image' => '',
        'image_alt' => '',
        'sort_order' => '0',
        'is_active' => true,
    ];
}

function normalizeAdminProduct(array $product): array
{
    $product['id'] = (int) $product['id'];
    $product['price_cents'] = (int) $product['price_cents'];
    $whole = intdiv($product['price_cents'], 100);
    $fraction = $product['price_cents'] % 100;
    $product['price'] = sprintf('%d,%02d', $whole, $fraction);
    $product['sort_order'] = (string) $product['sort_order'];
    $product['is_active'] = (bool) $product['is_active'];
    return $product;
}

function adminProducts(PDO $db): array
{
    $rows = $db->query(
        'SELECT revision, id, slug, name, description, category, price_cents, image, image_alt, sort_order, is_active
         FROM products ORDER BY sort_order, id',
    );
    return array_map('normalizeAdminProduct', $rows->fetchAll(PDO::FETCH_ASSOC));
}

function adminProduct(PDO $db, int $id): ?array
{
    $query = $db->prepare(
        'SELECT revision, id, slug, name, description, category, price_cents, image, image_alt, sort_order, is_active
         FROM products WHERE id = ?',
    );
    $query->execute([$id]);
    $product = $query->fetch(PDO::FETCH_ASSOC);
    return $product === false ? null : normalizeAdminProduct($product);
}

function adminProductPriceCents(string $price): ?int
{
    // A grouped input uses the Brazilian convention. A single dot may also be a decimal separator.
    if (preg_match('/\A\d{1,3}(?:\.\d{3})+,\d{1,2}\z/', $price)) {
        $price = str_replace('.', '', $price);
    }
    if (!preg_match('/\A(\d{1,8})(?:[,.](\d{1,2}))?\z/', $price, $parts)) {
        return null;
    }
    $cents = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    return $cents <= 4294967295 ? $cents : null;
}

function validateAdminProduct(array $input, ?PDO $db = null): array
{
    $values = ['revision' => filter_var($input['revision'] ?? null, FILTER_VALIDATE_INT) ?: 0];
    $errors = [];
    foreach (['name', 'description', 'category', 'price', 'image_alt', 'sort_order'] as $key) {
        $values[$key] = is_string($input[$key] ?? null) ? trim($input[$key]) : '';
    }
    $values['image'] = is_string($input['image'] ?? null) ? $input['image'] : '';
    $values['is_active'] = in_array($input['is_active'] ?? null, ['1', 'on'], true);

    $textFields = [
        'name' => ['um nome', 100, true],
        'description' => ['uma descrição', 255, true],
        'image_alt' => ['um texto alternativo', 180, false],
    ];
    foreach ($textFields as $key => [$label, $limit, $required]) {
        $value = $values[$key];
        $length = preg_match_all('/./us', $value);
        if ($length === false || $length > $limit || ($required && $length === 0)) {
            $errors[$key] = "Informe {$label} com até {$limit} caracteres.";
        }
    }
    if (!array_key_exists($values['category'], productCategories())) {
        $errors['category'] = 'Selecione uma categoria válida.';
    }
    $values['price_cents'] = adminProductPriceCents($values['price']);
    if ($values['price_cents'] === null) {
        $errors['price'] = 'Informe um preço entre R$ 0,00 e R$ 42.949.672,95, com até duas casas decimais.';
    }
    if ($values['image'] !== '' && !in_array($values['image'], adminProductImages(), true)) {
        $found = false;
        if ($db && preg_match('/\Aphoto:([a-f0-9]{64})\z/', $values['image'], $match)) {
            $query = $db->prepare('SELECT 1 FROM product_photos WHERE digest = ?');
            $query->execute([$match[1]]);
            $found = (bool) $query->fetchColumn();
        }
        if (!$found) {
            $errors['image'] = 'Selecione uma imagem do catálogo.';
        }
    }
    if (!preg_match('/\A\d{1,5}\z/', $values['sort_order']) || (int) $values['sort_order'] > 65535) {
        $errors['sort_order'] = 'Informe um número inteiro de 0 a 65535.';
    }
    return [$values, $errors];
}

function adminProductSlug(string $name): string
{
    $name = strtr($name, [
        'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'Ç' => 'C', 'ç' => 'c', 'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
    ]);
    $slug = trim(substr(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), 0, 63), '-');
    return $slug === '' ? 'produto' : $slug;
}

function saveAdminProduct(PDO $db, ?int $id, array $values): int
{
    $parameters = [
        $values['name'], $values['description'], $values['category'], $values['price_cents'], $values['image'],
        $values['image_alt'], (int) $values['sort_order'], (int) $values['is_active'],
    ];
    if ($id !== null) {
        $query = $db->prepare(
            'UPDATE products SET name = ?, description = ?, category = ?, price_cents = ?, image = ?,
             image_alt = ?, sort_order = ?, is_active = ?, revision = revision + 1 WHERE id = ? AND revision = ?',
        );
        $query->execute([...$parameters, $id, $values['revision']]);
        if ($query->rowCount() === 0) {
            throw new DomainException('O cadastro mudou. Confira os dados atuais e reaplique sua alteração.');
        }
        return $id;
    }

    $slug = adminProductSlug($values['name']);
    $query = $db->prepare(
        'INSERT INTO products (slug, name, description, category, price_cents, image, image_alt, sort_order, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
    );
    try {
        $query->execute([$slug, ...$parameters]);
    } catch (PDOException $exception) {
        if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
            throw $exception;
        }
        // The unique index arbitrates concurrent inserts that produce the same slug.
        $query->execute([$slug . '-' . bin2hex(random_bytes(8)), ...$parameters]);
    }
    return (int) $db->lastInsertId();
}
