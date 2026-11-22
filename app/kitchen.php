<?php

declare(strict_types=1);

require_once __DIR__ . '/orders.php';

function changeKitchenOrderStatus(PDO $db, int $id, string $expected, string $next, string $actor, string $reason = ''): bool
{
    if (!in_array($next, orderTransitions($expected), true)) {
        return false;
    }

    if (!preg_match('/\A[^\p{C}]{1,255}\z/u', $actor)) {
        throw new DomainException('O nome de usuário excede o limite do histórico de pedidos ou tem caracteres inválidos.');
    }

    $reason = $next === 'cancelled' ? trim(str_replace(["\r\n", "\r"], "\n", $reason)) : '';
    if ($next === 'cancelled' && !preg_match('/\A(?:[^\p{C}]|[\n\t]){1,240}\z/u', $reason)) {
        throw new DomainException('Informe um motivo de cancelamento com até 240 caracteres.');
    }

    $db->beginTransaction();
    try {
        if (!changeOrderStatus($db, $id, $expected, $next)) {
            $db->rollBack();
            return false;
        }

        $query = $db->prepare(
            'INSERT INTO order_status_events (order_id, previous_status, next_status, actor, reason) VALUES (?, ?, ?, ?, ?)',
        );
        $query->execute([$id, $expected, $next, $actor, $reason]);
        $db->commit();
        return true;
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function listOrderStatusEvents(PDO $db, int $id): array
{
    $query = $db->prepare(
        'SELECT previous_status, next_status, actor, reason, created_at FROM order_status_events WHERE order_id = ? ORDER BY id',
    );
    $query->execute([$id]);
    return $query->fetchAll();
}

function listKitchenOrders(PDO $db): array
{
    $rows = $db->query(
        "SELECT orders.id, orders.public_code, orders.status, orders.notes, orders.created_at,
                order_items.quantity, order_items.product_name
         FROM orders
         LEFT JOIN order_items ON order_items.order_id = orders.id
         WHERE orders.status IN ('received', 'preparing', 'ready')
         ORDER BY orders.created_at, orders.id, order_items.product_id",
    );

    $orders = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $orders[$id] ??= [
            'id' => $id,
            'public_code' => $row['public_code'],
            'status' => $row['status'],
            'notes' => $row['notes'],
            'created_at' => $row['created_at'],
            'items' => [],
        ];
        if ($row['product_name'] !== null) {
            $orders[$id]['items'][] = ['name' => $row['product_name'], 'quantity' => (int) $row['quantity']];
        }
    }

    $stages = ['received' => [], 'preparing' => [], 'ready' => []];
    foreach ($orders as $order) {
        $stages[$order['status']][] = $order;
    }
    return $stages;
}
