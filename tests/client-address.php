<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../app/client-address.php';

$before = getenv('VERCEL');
$serverBefore = $_SERVER;
try {
    putenv('VERCEL');
    $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99';
    $_SERVER['HTTP_VERCEL'] = '1';
    testSame('192.0.2.10', clientAddress(), 'header não habilita confiança fora da Vercel');
    putenv('VERCEL=1');
    testSame('203.0.113.99', clientAddress(), 'runtime Vercel usa IP único resolvido pela borda');
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '2001:db8::1';
    testSame('2001:db8::1', clientAddress(), 'origem IPv6 é preservada');
    foreach (['203.0.113.1, 192.0.2.3', 'invalid', '', ['203.0.113.1']] as $invalid) {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = $invalid;
        testSame('192.0.2.10', clientAddress(), 'origem inválida ou cadeia não é aceita');
    }
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    $_SERVER['REMOTE_ADDR'] = 'invalid';
    testSame(null, clientAddress(), 'sem IP válido não fabrica uma origem');
} finally {
    putenv($before === false ? 'VERCEL' : 'VERCEL=' . $before);
    $_SERVER = $serverBefore;
}
