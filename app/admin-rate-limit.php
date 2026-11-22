<?php

declare(strict_types=1);

require_once __DIR__ . '/client-address.php';

function adminLoginClientKey(): string
{
    $address = inet_pton(clientAddress() ?? '');
    if (is_string($address) && strlen($address) === 16) {
        $mappedIpv4 = str_repeat("\0", 10) . "\xff\xff";
        $address = str_starts_with($address, $mappedIpv4) ? substr($address, 12) : substr($address, 0, 8);
    }

    return 'client:' . hash('sha256', $address ?: 'unknown');
}

/** Slow failed distributed guesses without letting another origin lock out a valid password. */
function adminAccountFailureDelayMicroseconds(int $failures): int
{
    return $failures < 25 ? 0 : min(1000000, 250000 * (1 + intdiv($failures - 25, 5)));
}

/**
 * @param null|callable(int):void $throttle Injectable only by trusted callers/tests; receives microseconds.
 * @return bool|null The credentials match, failed verification, or hit the rate limit.
 */
function checkAdminLogin(PDO $db, ?string $username, callable $verify, ?callable $throttle = null): ?bool
{
    $clientBucket = adminLoginClientKey();
    $limits = [$clientBucket => 5];
    $accountBucket = null;
    if ($username !== null) {
        $accountBucket = 'account:' . hash('sha256', $username);
        $limits[$accountBucket] = 25;
    }

    $db->beginTransaction();
    try {
        // Lock clients first and accounts second so concurrent checks share the same lock order.
        $reserve = $db->prepare(
            'INSERT INTO admin_login_attempts (bucket, attempts, expires_at)
             VALUES (?, 0, UTC_TIMESTAMP() + INTERVAL 5 MINUTE)
             ON DUPLICATE KEY UPDATE
                 attempts = IF(expires_at <= UTC_TIMESTAMP(), 0, attempts),
                 expires_at = IF(expires_at <= UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL 5 MINUTE, expires_at)',
        );
        $read = $db->prepare('SELECT attempts FROM admin_login_attempts WHERE bucket = ? FOR UPDATE');
        $counts = [];
        foreach ($limits as $bucket => $limit) {
            $reserve->execute([$bucket]);
            $read->execute([$bucket]);
            $attempts = (int) $read->fetchColumn();
            $read->closeCursor();
            // A hard account-wide lock lets unauthenticated clients deny the owner's valid login.
            // Only the origin is blocked before verification; account pressure slows failed guesses.
            if ($bucket === $clientBucket && $attempts >= $limit) {
                $db->commit();
                return null;
            }
            $counts[$bucket] = $attempts;
        }

        $verified = $verify();
        if (!$verified) {
            $increment = $db->prepare(
                'UPDATE admin_login_attempts
                 SET expires_at = IF(attempts + 1 = ?, UTC_TIMESTAMP() + INTERVAL 5 MINUTE, expires_at),
                     attempts = LEAST(attempts + 1, 65535) WHERE bucket = ?',
            );
            foreach ($limits as $bucket => $limit) {
                $increment->execute([$limit, $bucket]);
            }
        }
        $db->commit();
        if ($verified) {
            return true;
        }
        $delay = $accountBucket === null ? 0 : adminAccountFailureDelayMicroseconds($counts[$accountBucket] + 1);
        if ($delay > 0) {
            // Never sleep while holding the client/account row locks or an open transaction.
            ($throttle ?? static fn (int $microseconds) => usleep($microseconds))($delay);
        }
        return $counts[$clientBucket] + 1 >= 5 || $delay > 0 ? null : false;
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function pruneAdminLoginAttempts(PDO $db): void
{
    try {
        $db->exec('DELETE FROM admin_login_attempts WHERE expires_at <= UTC_TIMESTAMP() ORDER BY expires_at LIMIT 32');
    } catch (PDOException $exception) {
        error_log('Burger House limpeza de tentativas de login: ' . $exception->getMessage());
    }
}
