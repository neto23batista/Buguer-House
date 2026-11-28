<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/database.php';

$digest = $_GET['id'] ?? null;
if (!is_string($digest) || !preg_match('/\A[a-f0-9]{64}\z/', $digest)) {
    http_response_code(404);
    exit;
}
try {
    $query = databaseConnection()->prepare('SELECT jpeg FROM product_photos WHERE digest=?');
    $query->execute([$digest]);
    $jpeg = $query->fetchColumn();
} catch (PDOException $error) {
    http_response_code(503);
    exit;
}
if ($jpeg === false) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/jpeg');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=31536000, immutable');
echo $jpeg;
