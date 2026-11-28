<?php

declare(strict_types=1);

require_once __DIR__ . '/cash.php';

function orderTrackingToken(string $checkoutToken): string
{
    // Use the raw checkout token held by the originating session. The database stores its digest.
    return hash_hmac('sha256', 'burger-house:order-tracking:v1', $checkoutToken);
}

function saveOrderTracking(PDO $db, int $id, string $checkoutToken, array $store, DateTimeImmutable $confirmedAt): void
{
    $day = $confirmedAt->format('Y-m-d');
    $sequence = $db->prepare('INSERT INTO pickup_sequences(pickup_day,last_number) VALUES (?,1)
        ON DUPLICATE KEY UPDATE last_number=last_number+1');
    $sequence->execute([$day]);
    $db->prepare('INSERT INTO pickup_tickets(order_id,pickup_day,ticket_number)
        SELECT ?,pickup_day,last_number FROM pickup_sequences WHERE pickup_day=?')->execute([$id, $day]);

    $expectedAt = $confirmedAt->setTimezone(new DateTimeZone('UTC'))->modify('+' . (int) $store['prep_minutes'] . ' minutes');
    $query = $db->prepare(
        'INSERT INTO order_tracking (order_id, token_hash, expires_at, pickup_at, pickup_address)
         VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL 3 DAY, ?, ?)',
    );
    preg_match('/\A.{0,250}/us', $store['pickup_address'], $address);
    $query->execute([$id, hash('sha256', orderTrackingToken($checkoutToken)), $expectedAt->format('Y-m-d H:i:s'), $address[0] ?? '']);
}

function orderTrackingDetails(PDO $db, string $code): ?array
{
    $query = $db->prepare(
        'SELECT orders.id, orders.public_code, orders.status, orders.total_cents, orders.created_at,
                ' . EFFECTIVE_PAYMENT_METHOD_SQL . ' AS payment_method, ticket.ticket_number, ticket.pickup_day, tracking.token_hash, tracking.pickup_at, tracking.pickup_address,
                (SELECT event.customer_reason FROM order_status_events AS event
                 WHERE event.order_id = orders.id AND event.next_status = \'cancelled\' ORDER BY event.id DESC LIMIT 1) AS customer_reason,
                tracking.expires_at > UTC_TIMESTAMP() AS is_current
         FROM orders LEFT JOIN order_tracking AS tracking ON tracking.order_id = orders.id
         LEFT JOIN payments AS payment ON payment.order_id=orders.id
         LEFT JOIN pickup_tickets AS ticket ON ticket.order_id=orders.id
         WHERE orders.public_code = ?',
    );
    $query->execute([$code]);
    return $query->fetch() ?: null;
}

function canReadOrderTracking(array $order, mixed $token): bool
{
    return (bool) $order['is_current'] && is_string($token)
        && preg_match('/\A[a-f0-9]{64}\z/', $token) === 1
        && hash_equals($order['token_hash'], hash('sha256', $token));
}

function orderReceiptUrl(string $code, ?string $token = null): string
{
    $parameters = ['pedido' => $code];
    if ($token !== null) {
        $parameters['chave'] = $token;
    }
    return 'checkout.php?' . http_build_query($parameters);
}

function orderPickupTime(array $order): ?string
{
    if (!$order['pickup_at']) {
        return null;
    }
    return (new DateTimeImmutable($order['pickup_at'], new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('d/m · H:i');
}
