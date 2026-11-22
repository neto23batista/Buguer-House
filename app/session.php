<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

function startBurgerSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    $requireSecureCookie = filter_var(getenv('SESSION_COOKIE_SECURE'), FILTER_VALIDATE_BOOLEAN);
    try {
        $driver = databaseSetting('SESSION_DRIVER', productionEnvironment() ? 'mysql' : 'files');
        $ttl = environmentInteger('SESSION_TTL_SECONDS', 43200, 43200, 604800);
        if (!in_array($driver, ['mysql', 'files'], true) || (productionEnvironment() && $driver !== 'mysql')) {
            throw new RuntimeException('Configure SESSION_DRIVER=mysql para produção.');
        }
        ini_set('session.gc_maxlifetime', (string) $ttl);
        if ($driver === 'mysql') {
            require_once __DIR__ . '/mysql-session.php';
            // Up to one new session per request; 1/100 * 500 gives spare cleanup capacity.
            // Scheduled maintenance also drains expired rows when there is little traffic.
            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '100');
            session_set_save_handler(new MySqlSessionHandler(static fn (): PDO => databaseConnection(), $ttl), true);
        }
        if (!session_start([
            'use_strict_mode' => 1,
            'use_only_cookies' => 1,
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'cookie_secure' => productionEnvironment() || $requireSecureCookie || !in_array($https, ['', '0', 'off'], true),
        ])) {
            throw new RuntimeException('Não foi possível iniciar a sessão.');
        }
    } catch (Throwable $exception) {
        error_log('Burger House sessão indisponível: ' . $exception->getMessage());
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('Retry-After: 30');
        exit('<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Burger House</title><h1>Voltamos em instantes</h1><p>Não foi possível carregar sua sessão. Aguarde um momento e recarregue a página.</p></html>');
    }
}
