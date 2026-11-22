<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$secret = getenv('CRON_SECRET');
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!is_string($secret) || strlen($secret) < 32 || !is_string($authorization)
    || !hash_equals('Bearer ' . $secret, $authorization)) {
    http_response_code(404);
    echo '{"status":"not_found"}';
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo '{"status":"method_not_allowed"}';
    exit;
}

try {
    require_once __DIR__ . '/../../bin/maintenance.php';
    $result = runMaintenance(databaseConnection());
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
    echo json_encode([
        'status' => 'ok',
        'maintenance' => $result,
        'client_origin' => [
            'platform_forwarded' => getenv('VERCEL') === '1' && is_string($forwarded)
                && filter_var(trim($forwarded), FILTER_VALIDATE_IP) !== false,
            'resolved' => clientAddress() !== null,
        ],
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('Burger House manutenção: ' . $exception->getMessage());
    http_response_code(503);
    echo '{"status":"unavailable"}';
}
