<?php

declare(strict_types=1);

require __DIR__ . '/../app/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

try {
    $db = databaseConnection();
    if ($db->query('SELECT id FROM store_settings WHERE id = 1')->fetchColumn() === false) {
        throw new RuntimeException('Configurações da loja ausentes.');
    }
    $db->query('SELECT EXISTS(SELECT session_id FROM web_sessions), EXISTS(SELECT ticket_number FROM pickup_tickets),
        EXISTS(SELECT method FROM payments), EXISTS(SELECT revision FROM payment_method_changes)');
    echo '{"status":"ok"}';
} catch (Throwable $exception) {
    error_log('Burger House health: ' . $exception->getMessage());
    http_response_code(503);
    echo '{"status":"unavailable"}';
}
