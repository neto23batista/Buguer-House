<?php

declare(strict_types=1);

require_once __DIR__ . '/client-address.php';

function databaseSetting(string $key, string $default): string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function environmentInteger(string $key, int $default, int $minimum, int $maximum): int
{
    $value = filter_var(databaseSetting($key, (string) $default), FILTER_VALIDATE_INT, [
        'options' => ['min_range' => $minimum, 'max_range' => $maximum],
    ]);
    if ($value === false) {
        throw new PDOException("Configuração inválida: {$key}.");
    }
    return $value;
}

function productionEnvironment(): bool
{
    return databaseSetting('APP_ENV', 'development') === 'production';
}

function databaseCertificate(): ?string
{
    static $temporary = null;
    $path = databaseSetting('DB_SSL_CA', '');
    $encoded = databaseSetting('DB_SSL_CA_BASE64', '');
    if ($path !== '' && $encoded !== '') {
        throw new PDOException('Configure apenas DB_SSL_CA ou DB_SSL_CA_BASE64.');
    }
    if ($path !== '') {
        if (!is_readable($path)) {
            throw new PDOException('O certificado DB_SSL_CA não pode ser lido.');
        }
        return $path;
    }
    if ($encoded === '') {
        return null;
    }
    $pem = base64_decode($encoded, true);
    if ($pem === false || !str_contains($pem, '-----BEGIN CERTIFICATE-----')) {
        throw new PDOException('DB_SSL_CA_BASE64 deve conter um certificado PEM em base64.');
    }
    if ($temporary !== null && $temporary['digest'] === hash('sha256', $pem)) {
        return $temporary['path'];
    }
    $file = tempnam(sys_get_temp_dir(), 'bh-ca-');
    if ($file === false) {
        throw new PDOException('Não foi possível preparar o certificado do banco.');
    }
    chmod($file, 0600);
    if (file_put_contents($file, $pem, LOCK_EX) !== strlen($pem)) {
        unlink($file);
        throw new PDOException('Não foi possível preparar o certificado do banco.');
    }
    register_shutdown_function(static function () use ($file): void {
        if (is_file($file)) {
            unlink($file);
        }
    });
    $temporary = ['digest' => hash('sha256', $pem), 'path' => $file];
    return $file;
}

function databaseConfiguration(): array
{
    $host = databaseSetting('DB_HOST', '127.0.0.1');
    $database = databaseSetting('DB_NAME', 'burger_house');
    if (!preg_match('/\A[a-zA-Z0-9._:\[\]-]+\z/', $host)
        || !preg_match('/\A[a-zA-Z0-9_-]+\z/', $database)) {
        throw new PDOException('DB_HOST ou DB_NAME inválido.');
    }
    $port = environmentInteger('DB_PORT', 3306, 1, 65535);
    $timeout = environmentInteger('DB_CONNECT_TIMEOUT', 5, 1, 30);
    $readTimeout = environmentInteger('DB_READ_TIMEOUT', 10, 1, 60);
    $local = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    $required = filter_var(databaseSetting('DB_SSL_REQUIRED', productionEnvironment() && !$local ? '1' : '0'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($required === null || (productionEnvironment() && !$local && !$required)) {
        throw new PDOException('TLS é obrigatório para o banco remoto em produção.');
    }
    $certificate = databaseCertificate();
    $tls = $required || $certificate !== null;
    if ($tls && $certificate === null) {
        $systemCa = '/etc/ssl/certs/ca-certificates.crt';
        if (!is_readable($systemCa)) {
            throw new PDOException('Configure DB_SSL_CA ou DB_SSL_CA_BASE64 para verificar o banco.');
        }
        $certificate = $systemCa;
    }
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => $timeout,
    ];
    if ($tls) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $certificate;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }
    return [
        'dsn' => "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        'options' => $options, 'tls' => $tls, 'read_timeout' => $readTimeout,
    ];
}

function databaseConnection(): PDO
{
    $config = databaseConfiguration();
    // The runtime uses mysqlnd; PDO::ATTR_TIMEOUT only bounds the connection handshake.
    ini_set('mysqlnd.net_read_timeout', (string) $config['read_timeout']);
    $connection = new PDO(
        $config['dsn'], databaseSetting('DB_USER', 'bh_web'), databaseSetting('DB_PASSWORD', ''), $config['options'],
    );
    if ($config['tls']) {
        $status = $connection->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch();
        if (!$status || ($status['Value'] ?? '') === '') {
            throw new PDOException('O banco não estabeleceu a conexão TLS exigida.');
        }
    }
    $connection->exec("SET time_zone = '+00:00'");
    $connection->exec('SET SESSION innodb_lock_wait_timeout = 5');
    return $connection;
}
