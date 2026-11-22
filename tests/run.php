<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$suites = ['database.php', 'client-address.php', 'migrations.php', 'cart.php', 'store.php', 'session.php'];
$failures = 0;
foreach ($suites as $suite) {
    $path = __DIR__ . '/' . $suite;
    if (!is_file($path)) {
        fwrite(STDERR, 'Suíte ausente: ' . $suite . PHP_EOL);
        $failures++;
        continue;
    }
    $process = proc_open([PHP_BINARY, 'tests/' . $suite], [STDIN, STDOUT, STDERR], $pipes, $root);
    if (!is_resource($process) || proc_close($process) !== 0) {
        $failures++;
    }
}
$process = proc_open(['node', 'tests/cart.test.cjs'], [STDIN, STDOUT, STDERR], $pipes, $root);
if (!is_resource($process) || proc_close($process) !== 0) {
    $failures++;
}
$runDatabase = getenv('DB_TEST_DSN') !== false || getenv('REQUIRE_DB_TESTS') === '1';
if ($runDatabase) {
    foreach (['integration.php', 'functional-regressions.php', 'security-regressions.php'] as $suite) {
        $process = proc_open([PHP_BINARY, 'tests/' . $suite], [STDIN, STDOUT, STDERR], $pipes, $root);
        if (!is_resource($process) || proc_close($process) !== 0) {
            $failures++;
        }
    }
} else {
    echo 'SKIP: integração MySQL (configure DB_TEST_DSN ou REQUIRE_DB_TESTS=1).', PHP_EOL;
}
exit($failures === 0 ? 0 : 1);
