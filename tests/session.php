<?php

declare(strict_types=1);

require __DIR__ . '/../app/session.php';

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$folder = sys_get_temp_dir() . '/bh-session-test-' . bin2hex(random_bytes(6));
mkdir($folder, 0700);
putenv('APP_ENV=development');
putenv('SESSION_DRIVER=files');
putenv('SESSION_TTL_SECONDS=43200');
session_save_path($folder);
try {
    session_id('unknown-cookie-value');
    startBurgerSession();
    $id = session_id();
    $check($id !== 'unknown-cookie-value', 'Strict mode must reject an unissued ID.');
    $check(session_get_cookie_params()['httponly'], 'Cookie must be HttpOnly.');
    $check(session_get_cookie_params()['samesite'] === 'Lax', 'Cookie must use SameSite Lax.');
    $check((int) ini_get('session.gc_maxlifetime') === 43200, 'Storage must retain the full administrative lifetime.');
    $_SESSION['cart'] = [1 => 2];
    session_write_close();
    $_SESSION = [];
    session_id($id);
    startBurgerSession();
    $check($_SESSION['cart'] === [1 => 2], 'The cart must survive a new session read.');
    session_regenerate_id(true);
    $regenerated = session_id();
    session_write_close();
    $check($id !== $regenerated && !is_file($folder . '/sess_' . $id), 'Login regeneration must invalidate the old session.');
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    foreach (glob($folder . '/sess_*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($folder);
}
echo "OK session: strict cookie validation, persistence, lifetime and regeneration\n";
