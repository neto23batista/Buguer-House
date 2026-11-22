<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/database.php';

/** @return array{batch_size:int,max_batches:int,max_seconds:int,pii_retention_days:int} */
function maintenanceConfiguration(): array
{
    return [
        'batch_size' => environmentInteger('MAINTENANCE_BATCH_SIZE', 500, 1, 5000),
        'max_batches' => environmentInteger('MAINTENANCE_MAX_BATCHES', 20, 1, 1000),
        'max_seconds' => environmentInteger('MAINTENANCE_MAX_SECONDS', 10, 1, 120),
        // Zero is deliberately opt-out until the owner chooses a data-retention period.
        'pii_retention_days' => environmentInteger('ORDER_PII_RETENTION_DAYS', 0, 0, 36500),
    ];
}

/**
 * Removes only expired transient records. Customer PII anonymization is explicitly opt-in.
 * A scheduler can import this file and supply its authenticated, least-privilege connection.
 * Budgets are checked between bounded SQL batches; connection/query timeouts still apply.
 * @param null|array{batch_size:int,max_batches:int,max_seconds:int,pii_retention_days:int} $configuration
 * @return array<string,mixed>
 */
function runMaintenance(PDO $db, ?array $configuration = null, bool $dryRun = false): array
{
    if ($db->inTransaction()) {
        throw new RuntimeException('Execute a manutenção fora de uma transação.');
    }
    $configuration ??= maintenanceConfiguration();
    foreach ([
        'batch_size' => [1, 5000], 'max_batches' => [1, 1000],
        'max_seconds' => [1, 120], 'pii_retention_days' => [0, 36500],
    ] as $key => [$minimum, $maximum]) {
        $value = $configuration[$key] ?? null;
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException('Configuração inválida da manutenção: ' . $key . '.');
        }
    }
    $batch = $configuration['batch_size'];
    $retention = $configuration['pii_retention_days'];
    $jobs = [
        'sessions_deleted' => ['web_sessions', 'expires_at <= UTC_TIMESTAMP()', 'expires_at', null],
        'login_limits_deleted' => ['admin_login_attempts', 'expires_at <= UTC_TIMESTAMP()', 'expires_at', null],
        'order_limits_deleted' => ['order_rate_limits', 'expires_at <= UTC_TIMESTAMP()', 'expires_at', null],
    ];
    if ($retention > 0) {
        $where = "status IN ('completed', 'cancelled')
            AND created_at < TIMESTAMPADD(DAY, -{$retention}, UTC_TIMESTAMP())
            AND updated_at < TIMESTAMPADD(DAY, -{$retention}, UTC_TIMESTAMP())
            AND (customer_name <> 'Cliente anonimizado' OR customer_phone <> '' OR notes <> '')";
        $jobs['orders_anonymized'] = ['orders', $where, 'created_at, id',
            "customer_name = 'Cliente anonimizado', customer_phone = '', notes = ''"];
    }
    $result = [
        'dry_run' => $dryRun,
        'skipped' => false,
        'limited' => false,
        'anonymization_enabled' => $retention > 0,
        'pii_retention_days' => $retention,
        'counts' => array_fill_keys(array_keys($jobs), 0),
    ];
    if ($dryRun) {
        // Report at most one invocation's row budget, without returning any customer data.
        $maximum = $batch * $configuration['max_batches'];
        foreach ($jobs as $key => [$table, $where]) {
            $count = (int) $db->query("SELECT COUNT(*) FROM (SELECT 1 FROM {$table} WHERE {$where} LIMIT {$maximum}) AS eligible")->fetchColumn();
            $result['counts'][$key] = $count;
            $result['limited'] = $result['limited'] || $count === $maximum;
        }
        return $result;
    }
    $database = $db->query('SELECT DATABASE()')->fetchColumn();
    if (!is_string($database) || $database === '') {
        throw new RuntimeException('Selecione um banco existente antes de executar a manutenção.');
    }
    $lockName = 'bh-maintenance:' . substr(hash('sha256', $database), 0, 49);
    $lock = $db->prepare('SELECT GET_LOCK(?, 0)');
    $lock->execute([$lockName]);
    if ((int) $lock->fetchColumn() !== 1) {
        $result['skipped'] = true;
        return $result;
    }
    $deadline = microtime(true) + $configuration['max_seconds'];
    try {
        $pending = $jobs;
        for ($pass = 0; $pass < $configuration['max_batches'] && $pending !== []; $pass++) {
            foreach ($pending as $key => [$table, $where, $order, $assignments]) {
                if (microtime(true) >= $deadline) {
                    $result['limited'] = true;
                    break 2;
                }
                $sql = $assignments === null
                    ? "DELETE FROM {$table} WHERE {$where} ORDER BY {$order} LIMIT {$batch}"
                    : "UPDATE {$table} SET {$assignments} WHERE {$where} ORDER BY {$order} LIMIT {$batch}";
                $changed = $db->exec($sql);
                $result['counts'][$key] += $changed;
                if ($changed < $batch) {
                    unset($pending[$key]);
                }
            }
        }
        $result['limited'] = $result['limited'] || $pending !== [];
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
    return $result;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }
    $arguments = array_slice($argv, 1);
    if (in_array('--help', $arguments, true)) {
        echo "Uso: php bin/maintenance.php [--dry-run]\n",
            "MAINTENANCE_BATCH_SIZE=500; MAINTENANCE_MAX_BATCHES=20; MAINTENANCE_MAX_SECONDS=10\n",
            "ORDER_PII_RETENTION_DAYS=0 desativa anonimização; configure o prazo explicitamente para habilitar.\n";
        exit;
    }
    try {
        if (array_diff($arguments, ['--dry-run']) !== []) {
            throw new InvalidArgumentException('Argumento desconhecido. Use --help.');
        }
        echo json_encode(runMaintenance(databaseConnection(), null, in_array('--dry-run', $arguments, true)),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), PHP_EOL;
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Manutenção falhou: ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}
