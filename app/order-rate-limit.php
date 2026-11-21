<?php

declare(strict_types=1);

const ORDER_WINDOW_SECONDS = 900;
const MAX_ORDERS_PER_PHONE = 3;
const MAX_ORDERS_PER_ORIGIN = 20;
const MAX_ORDER_ITEMS = 40;

final class OrderRateLimitException extends DomainException
{
    public function __construct(string $message, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}

function pruneExpiredOrderLimits(PDO $db): void
{
    if (random_int(1, 32) !== 1) {
        return;
    }

    try {
        $db->exec('DELETE FROM order_rate_limits WHERE expires_at <= UTC_TIMESTAMP() ORDER BY expires_at LIMIT 200');
    } catch (PDOException $exception) {
        error_log('Burger House: falha ao limpar os limites de pedidos expirados.');
    }
}

function consumeOrderQuota(PDO $db, string $phone, ?string $clientAddress): void
{
    // All checkouts take their quota locks in phone, then origin order.
    $limits = [hash('sha256', "order-phone\0" . $phone) => MAX_ORDERS_PER_PHONE];
    $packedAddress = $clientAddress === null ? false : inet_pton($clientAddress);
    if ($packedAddress !== false) {
        $mappedV4Prefix = str_repeat("\0", 10) . "\xff\xff";
        $origin = str_starts_with($packedAddress, $mappedV4Prefix)
            ? substr($packedAddress, 12)
            : substr($packedAddress, 0, 8);
        $limits[hash('sha256', "order-origin\0" . $origin)] = MAX_ORDERS_PER_ORIGIN;
    }

    $reset = $db->prepare(
        'INSERT INTO order_rate_limits (source_hash, order_count, expires_at)
         VALUES (?, 0, TIMESTAMPADD(SECOND, ?, UTC_TIMESTAMP())) AS next_window
         ON DUPLICATE KEY UPDATE
             order_count = IF(order_rate_limits.expires_at <= UTC_TIMESTAMP(), 0, order_rate_limits.order_count),
             expires_at = IF(order_rate_limits.expires_at <= UTC_TIMESTAMP(), next_window.expires_at, order_rate_limits.expires_at)',
    );
    $status = $db->prepare(
        'SELECT order_count, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), expires_at) AS seconds_left
         FROM order_rate_limits WHERE source_hash = ? FOR UPDATE',
    );
    $increment = $db->prepare('UPDATE order_rate_limits SET order_count = order_count + 1 WHERE source_hash = ?');

    foreach ($limits as $key => $maximum) {
        $reset->execute([$key, ORDER_WINDOW_SECONDS]);
        $status->execute([$key]);
        $current = $status->fetch(PDO::FETCH_ASSOC);
        if ((int) $current['order_count'] >= $maximum) {
            throw new OrderRateLimitException(
                'O limite de pedidos recentes foi atingido. Aguarde alguns minutos e tente novamente.',
                max(1, (int) $current['seconds_left']),
            );
        }
        $increment->execute([$key]);
    }
}
