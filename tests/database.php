<?php

declare(strict_types=1);

require __DIR__ . '/../app/database.php';

function rejectsConfiguration(string $name, callable $change): void
{
    $change();
    try {
        databaseConfiguration();
    } catch (PDOException) {
        echo "OK configuration rejects {$name}\n";
        return;
    }
    throw new RuntimeException("Configuration accepted {$name}");
}

foreach (['DB_SSL_CA', 'DB_SSL_CA_BASE64', 'DB_CONNECT_TIMEOUT', 'DB_READ_TIMEOUT', 'DB_SSL_REQUIRED'] as $key) {
    putenv($key);
}
putenv('APP_ENV=development');
putenv('DB_HOST=127.0.0.1');
putenv('DB_NAME=burger_house_test');
$configuration = databaseConfiguration();
if (!str_contains($configuration['dsn'], 'dbname=burger_house_test') || $configuration['tls']) {
    throw new RuntimeException('Local test configuration did not preserve its selected schema.');
}
rejectsConfiguration('DSN injection', static fn () => putenv('DB_HOST=localhost;dbname=other'));
putenv('DB_HOST=127.0.0.1');
rejectsConfiguration('unbounded timeout', static fn () => putenv('DB_READ_TIMEOUT=0'));
putenv('DB_READ_TIMEOUT=10');
putenv('DB_HOST=database.example.com');
putenv('APP_ENV=production');
rejectsConfiguration('plaintext remote production DB', static fn () => putenv('DB_SSL_REQUIRED=0'));
putenv('DB_SSL_REQUIRED=1');
rejectsConfiguration('invalid encoded CA', static fn () => putenv('DB_SSL_CA_BASE64=invalid'));
echo "OK database configuration\n";
