<?php

declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/catalog.php';

const ADMIN_IDLE_SECONDS = 8 * 3600;
const ADMIN_SESSION_SECONDS = 12 * 3600;

function beginAdminSession(bool $passive = false): array
{
    startBurgerSession();
    header('Cache-Control: no-store, private');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Frame-Options: DENY');

    $accounts = [
        'owner' => adminAccount(databaseSetting('ADMIN_USER', 'admin'), databaseSetting('ADMIN_PASSWORD_HASH', '')),
        'operator' => adminAccount(databaseSetting('OPERATOR_USER', ''), databaseSetting('OPERATOR_PASSWORD_HASH', '')),
    ];
    if ($accounts['operator']['user'] === $accounts['owner']['user']) {
        $accounts['operator']['configured'] = false;
    }

    $adminSession = $_SESSION['admin_session'] ?? [];
    $csrf = $_SESSION['admin_csrf'] ??= bin2hex(random_bytes(32));
    $role = $adminSession['role'] ?? 'owner';
    $active = $accounts[$role] ?? $accounts['owner'];
    $configured = $accounts['owner']['configured'];
    $now = time();
    $lastSeen = $adminSession['last_seen'] ?? 0;
    $startedAt = $adminSession['started_at'] ?? $lastSeen;
    $loggedIn = $configured && $active['configured'] && ($adminSession['version'] ?? null) === $active['version']
        && $lastSeen > $now - ADMIN_IDLE_SECONDS && $startedAt > $now - ADMIN_SESSION_SECONDS;

    if ($loggedIn) {
        $_SESSION['admin_session']['started_at'] = $startedAt;
        if (!$passive) {
            $_SESSION['admin_session']['last_seen'] = $now;
        }
    } else {
        unset($_SESSION['admin_session']);
    }

    $user = $active['user'];
    return compact('user', 'accounts', 'role', 'configured', 'loggedIn', 'csrf');
}

function adminAccount(string $user, string $hash): array
{
    $algorithm = password_get_info($hash)['algo'];
    $configured = preg_match('/\A[^\p{C}]{1,255}\z/u', $user) === 1 && $algorithm !== null;
    $version = hash('sha256', $user . "\0" . $hash);
    return compact('user', 'hash', 'algorithm', 'configured', 'version');
}
