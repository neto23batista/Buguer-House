<?php

declare(strict_types=1);

function startBurgerSession(): void
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    $requireSecureCookie = filter_var(getenv('SESSION_COOKIE_SECURE'), FILTER_VALIDATE_BOOLEAN);
    session_start([
        'use_strict_mode' => 1,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure' => $requireSecureCookie || !in_array($https, ['', '0', 'off'], true),
    ]);
}
