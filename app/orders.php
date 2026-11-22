<?php

declare(strict_types=1);

require_once __DIR__ . '/cart.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/tracking.php';
require_once __DIR__ . '/order-rate-limit.php';

const ORDER_FIELDS = 'id, public_code, customer_name, customer_phone, notes, total_cents, status, created_at, updated_at';

function orderStatuses(): array
{
    return [
        'received' => 'Recebido',
        'preparing' => 'Em preparo',
        'ready' => 'Pronto para retirada',
        'completed' => 'Concluído',
        'cancelled' => 'Cancelado',
    ];
}

function orderTransitions(string $status): array
{
    return match ($status) {
        'received' => ['preparing', 'cancelled'],
        'preparing' => ['ready', 'cancelled'],
        'ready' => ['completed', 'cancelled'],
        default => [],
    };
}

function cartFingerprint(array $cart): string
{
    $items = [];
    foreach ($cart['items'] as $item) {
        $product = $item['product'];
        $items[$product['id']] = [$item['quantity'], $product['price_cents']];
    }
    ksort($items, SORT_NUMERIC);

    return hash('sha256', json_encode($items, JSON_THROW_ON_ERROR));
}

function placeOrder(
    PDO $db,
    string $checkoutToken,
    array $customer,
    array $rawQuantities,
    string $expectedCart,
    string $expectedPickupAddress,
    ?string $clientAddress = null,
): array {
    if (!preg_match('/\A[a-f0-9]{64}\z/', $checkoutToken)) {
        throw new DomainException('A confirmação do pedido expirou. Confira seu carrinho e tente novamente.');
    }

    $existingOrder = findOrderByCheckoutToken($db, $checkoutToken);
    if ($existingOrder !== null) {
        return $existingOrder;
    }
    if ($rawQuantities === []) {
        throw new DomainException('Adicione um item ao carrinho antes de confirmar o pedido.');
    }

    pruneExpiredOrderLimits($db);
    $db->beginTransaction();
    try {
        $store = loadStore($db, true);
        $availability = storeAvailability($store);
        if (!$availability['accepting']) {
            throw new DomainException($availability['message']);
        }
        if ($store['pickup_address'] !== $expectedPickupAddress) {
            throw new DomainException('O endereço de retirada mudou. Confira o novo endereço antes de confirmar.');
        }

        // Preserve the price and availability of every requested product until the order is saved.
        $placeholders = implode(', ', array_fill(0, count($rawQuantities), '?'));
        $productsQuery = $db->prepare(
            "SELECT id, name, price_cents FROM products
             WHERE id IN ({$placeholders}) AND is_active = 1 ORDER BY id FOR UPDATE",
        );
        $productsQuery->execute(array_keys($rawQuantities));
        $products = [];
        foreach ($productsQuery->fetchAll(PDO::FETCH_ASSOC) as $product) {
            $product['id'] = (int) $product['id'];
            $product['price_cents'] = (int) $product['price_cents'];
            $products[$product['id']] = $product;
        }

        $quantities = availableQuantities($rawQuantities, $products);
        if ($quantities !== $rawQuantities) {
            throw new DomainException('Seu carrinho tem itens indisponíveis ou quantidades inválidas. Confira os itens antes de confirmar.');
        }
        $cart = cartSummary($quantities, $products);
        if (!hash_equals($expectedCart, cartFingerprint($cart))) {
            throw new DomainException('Seu carrinho mudou. Confira os itens e os preços antes de confirmar.');
        }
        if ($cart['count'] > MAX_ORDER_ITEMS) {
            throw new OrderRateLimitException('O limite é de ' . MAX_ORDER_ITEMS . ' unidades por pedido. Ajuste o carrinho para continuar.');
        }
        consumeOrderQuota($db, $customer['phone'], $clientAddress);

        $publicCode = strtoupper(bin2hex(random_bytes(8)));
        $insert = $db->prepare(
            'INSERT INTO orders (public_code, checkout_token, customer_name, customer_phone, notes, total_cents)
             VALUES (?, ?, ?, ?, ?, ?)',
        );
        $insert->execute([
            $publicCode, checkoutTokenDigest($checkoutToken), $customer['name'], $customer['phone'], $customer['notes'], $cart['totalCents'],
        ]);
        $orderId = (int) $db->lastInsertId();

        $insertItem = $db->prepare(
            'INSERT INTO order_items (order_id, product_id, product_name, unit_price_cents, quantity) VALUES (?, ?, ?, ?, ?)',
        );
        foreach ($cart['items'] as $item) {
            $product = $item['product'];
            $insertItem->execute([$orderId, $product['id'], $product['name'], $product['price_cents'], $item['quantity']]);
        }
        saveOrderTracking($db, $orderId, $checkoutToken, $store);

        $order = findOrder($db, $publicCode);
        if ($order === null) {
            throw new RuntimeException('Não foi possível recuperar o pedido salvo.');
        }
        $db->commit();

        return $order;
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        // A concurrent request with the same token can commit while this one is in progress.
        if ($exception instanceof DomainException
            || ($exception instanceof PDOException && (int) ($exception->errorInfo[1] ?? 0) === 1062)
        ) {
            $existingOrder = findOrderByCheckoutToken($db, $checkoutToken);
            if ($existingOrder !== null) {
                return $existingOrder;
            }
        }

        throw $exception;
    }
}

function normalizeOrder(array $row): array
{
    $row['id'] = (int) $row['id'];
    $row['total_cents'] = (int) $row['total_cents'];

    return $row;
}

function findOrder(PDO $db, string $publicCode): ?array
{
    $query = $db->prepare('SELECT ' . ORDER_FIELDS . ' FROM orders WHERE public_code = ?');
    $query->execute([$publicCode]);
    $row = $query->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : normalizeOrder($row);
}

function findOrderByCheckoutToken(PDO $db, string $checkoutToken): ?array
{
    $query = $db->prepare('SELECT ' . ORDER_FIELDS . ' FROM orders WHERE checkout_token IN (?, ?)');
    $query->execute([checkoutTokenDigest($checkoutToken), $checkoutToken]);
    $row = $query->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : normalizeOrder($row);
}

function checkoutTokenDigest(string $checkoutToken): string
{
    return rtrim(strtr(base64_encode(hash('sha256', $checkoutToken, true)), '+/', '-_'), '=');
}

function listOrderItems(PDO $db, int $orderId): array
{
    $query = $db->prepare(
        'SELECT order_id, product_id, product_name, unit_price_cents, quantity
         FROM order_items WHERE order_id = ? ORDER BY product_id',
    );
    $query->execute([$orderId]);

    $items = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['order_id'] = (int) $row['order_id'];
        $row['product_id'] = (int) $row['product_id'];
        $row['unit_price_cents'] = (int) $row['unit_price_cents'];
        $row['quantity'] = (int) $row['quantity'];
        $items[] = $row;
    }

    return $items;
}

function listOrders(PDO $db, ?string $status = null, int $limit = 50, int $offset = 0): array
{
    $filter = $status === null ? '' : ' WHERE status = :status';
    $query = $db->prepare('SELECT ' . ORDER_FIELDS . ' FROM orders' . $filter . ' ORDER BY id DESC LIMIT :limit OFFSET :offset');
    if ($status !== null) {
        $query->bindValue(':status', $status);
    }
    $query->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
    $query->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
    $query->execute();

    return array_map('normalizeOrder', $query->fetchAll(PDO::FETCH_ASSOC));
}

function changeOrderStatus(PDO $db, int $id, string $expectedStatus, string $nextStatus): bool
{
    if (!in_array($nextStatus, orderTransitions($expectedStatus), true)) {
        return false;
    }

    $query = $db->prepare('UPDATE orders SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = ?');
    $query->execute([$nextStatus, $id, $expectedStatus]);

    return $query->rowCount() === 1;
}
