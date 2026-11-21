<?php

declare(strict_types=1);

function adminLoginClientKey(): string
{
    // The web server can resolve addresses from its own trusted proxies.
    $address = inet_pton($_SERVER['REMOTE_ADDR'] ?? '');
    if (is_string($address) && strlen($address) === 16) {
        $mappedIpv4 = str_repeat("\0", 10) . "\xff\xff";
        $address = str_starts_with($address, $mappedIpv4) ? substr($address, 12) : substr($address, 0, 8);
    }

    return 'client:' . hash('sha256', $address ?: 'unknown');
}

/** @return bool|null The credentials match, failed verification, or hit the rate limit. */
function checkAdminLogin(PDO $db, ?string $username, callable $verify): ?bool
{
    $limits = [adminLoginClientKey() => 5];
    if ($username !== null) {
        $limits['account:' . hash('sha256', $username)] = 25;
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
        $remaining = PHP_INT_MAX;
        foreach ($limits as $bucket => $limit) {
            $reserve->execute([$bucket]);
            $read->execute([$bucket]);
            $attempts = (int) $read->fetchColumn();
            $read->closeCursor();
            if ($attempts >= $limit) {
                $db->commit();
                return null;
            }
            $remaining = min($remaining, $limit - $attempts);
        }

        $verified = $verify();
        if (!$verified) {
            $increment = $db->prepare(
                'UPDATE admin_login_attempts
                 SET expires_at = IF(attempts + 1 = ?, UTC_TIMESTAMP() + INTERVAL 5 MINUTE, expires_at),
                     attempts = attempts + 1 WHERE bucket = ?',
            );
            foreach ($limits as $bucket => $limit) {
                $increment->execute([$limit, $bucket]);
            }
        }
        $db->commit();
        return !$verified && $remaining === 1 ? null : $verified;
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
