<?php

declare(strict_types=1);

function reportPeriod(array $input): array
{
    $timezone = new DateTimeZone('America/Sao_Paulo');
    $today = new DateTimeImmutable('today', $timezone);
    $defaultStart = $today->modify('-6 days');
    $start = reportDate($input['inicio'] ?? $defaultStart->format('Y-m-d'), $timezone);
    $end = reportDate($input['fim'] ?? $today->format('Y-m-d'), $timezone);
    $error = null;
    if (!$start || !$end || $start > $end || $start->diff($end)->days >= 366) {
        $error = 'Informe um período válido de até 366 dias.';
        [$start, $end] = [$defaultStart, $today];
    }

    $utc = new DateTimeZone('UTC');
    return [
        'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'error' => $error,
        'bounds' => [$start->setTimezone($utc)->format('Y-m-d H:i:s'), $end->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')],
    ];
}

function reportDate(mixed $input, DateTimeZone $timezone): ?DateTimeImmutable
{
    if (!is_string($input) || !preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}\z/', $input)) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $input, $timezone);
    return $date && $date->format('Y-m-d') === $input ? $date : null;
}

function orderReport(PDO $db, array $bounds): array
{
    $totals = $db->prepare(
        "SELECT COUNT(*) AS received, COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed,
                COUNT(CASE WHEN status = 'cancelled' THEN 1 END) AS cancelled,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN total_cents END), 0) AS total
         FROM orders WHERE created_at >= ? AND created_at < ?",
    );
    $totals->execute($bounds);

    $products = $db->prepare(
        "SELECT sales.product_id, COALESCE(products.name, snapshot.product_name) AS product_name, sales.units
         FROM (
             SELECT order_items.product_id, SUM(order_items.quantity) AS units, MAX(order_items.order_id) AS last_order_id
             FROM order_items JOIN orders ON orders.id = order_items.order_id
             WHERE orders.status = 'completed' AND orders.created_at >= ? AND orders.created_at < ?
             GROUP BY order_items.product_id
         ) AS sales
         LEFT JOIN products ON products.id = sales.product_id
         JOIN order_items AS snapshot ON snapshot.order_id = sales.last_order_id AND snapshot.product_id = sales.product_id
         ORDER BY sales.units DESC, product_name, sales.product_id LIMIT 8",
    );
    $products->execute($bounds);

    $cancellations = $db->prepare(
        "SELECT events.reason, COUNT(*) AS orders_count FROM order_status_events AS events JOIN orders ON orders.id = events.order_id
         WHERE events.next_status = 'cancelled' AND orders.created_at >= ? AND orders.created_at < ?
         GROUP BY events.reason ORDER BY orders_count DESC, events.reason LIMIT 8",
    );
    $cancellations->execute($bounds);

    return ['totals' => $totals->fetch(), 'products' => $products->fetchAll(), 'cancellations' => $cancellations->fetchAll()];
}
