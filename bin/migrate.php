<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/catalog.php';

/** @return array<string, string> Never edit a migration after it has been applied; add a new entry. */
function migrationManifest(): array
{
    return [
        '001_schema' => 'schema.sql',
        '002_store' => 'store.sql',
        '003_kitchen' => 'kitchen.sql',
        '004_tracking' => 'tracking.sql',
        '005_admin_security' => 'admin-security.sql',
        '006_order_security' => 'order-security.sql',
        '007_sessions' => 'sessions.sql',
        '008_report_indexes' => 'report-indexes.sql',
        '009_store_revision' => 'store-revision.sql',
        '010_product_revision' => 'product-revision.sql',
        '011_customer_reason' => 'customer-reason.sql',
        '012_operations' => 'operations.sql',
        '013_pickup_tickets' => 'pickup-tickets.sql',
        '014_payment_method_changes' => 'payment-method-changes.sql',
    ];
}

/** Split plain SQL files without treating semicolons inside strings or comments as statement endings. */
function migrationStatements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $quote = null;
    $lineComment = false;
    $blockComment = false;
    $length = strlen($sql);
    for ($index = 0; $index < $length; $index++) {
        $character = $sql[$index];
        $next = $sql[$index + 1] ?? '';
        if ($lineComment) {
            if ($character === "\n") {
                $lineComment = false;
                $buffer .= "\n";
            }
            continue;
        }
        if ($blockComment) {
            if ($character === '*' && $next === '/') {
                $blockComment = false;
                $index++;
                $buffer .= ' ';
            }
            continue;
        }
        if ($quote !== null) {
            $buffer .= $character;
            if ($character === '\\' && $next !== '') {
                $buffer .= $next;
                $index++;
            } elseif ($character === $quote) {
                if ($next === $quote) {
                    $buffer .= $next;
                    $index++;
                } else {
                    $quote = null;
                }
            }
            continue;
        }
        if (($character === '-' && $next === '-' && ctype_space($sql[$index + 2] ?? ' ')) || $character === '#') {
            $lineComment = true;
            if ($character === '-') {
                $index++;
            }
        } elseif ($character === '/' && $next === '*') {
            $blockComment = true;
            $index++;
        } elseif (in_array($character, ["'", '"', '`'], true)) {
            $quote = $character;
            $buffer .= $character;
        } elseif ($character === ';') {
            if (trim($buffer) !== '') {
                $statements[] = trim($buffer);
            }
            $buffer = '';
        } else {
            $buffer .= $character;
        }
    }
    if ($quote !== null || $blockComment) {
        throw new RuntimeException('Arquivo SQL com string ou comentário não terminado.');
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }
    return $statements;
}

/** TiDB does not allow UTC_TIMESTAMP() expressions in defaults; the connection is already UTC. */
function migrationSqlForServer(string $statement, string $serverVersion): string
{
    if (stripos($serverVersion, 'TiDB') === false
        || !preg_match('/\A\s*(?:CREATE|ALTER)\s+TABLE\b/i', $statement)) {
        return $statement;
    }
    return preg_replace('/\bDEFAULT\s*\(\s*UTC_TIMESTAMP\s*\(\s*\)\s*\)/i', 'DEFAULT CURRENT_TIMESTAMP', $statement);
}

/** Validate every historical checksum before running any pending SQL. */
function pendingMigrations(PDO $db): array
{
    $applied = $db->query('SELECT migration, checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
    $pending = [];
    foreach (migrationManifest() as $migration => $file) {
        $sql = file_get_contents(__DIR__ . '/../database/' . $file);
        if ($sql === false) {
            throw new RuntimeException('Migração ausente: ' . $file);
        }
        $checksum = hash('sha256', str_replace("\r\n", "\n", $sql));
        if (!isset($applied[$migration])) {
            $pending[$migration] = ['checksum' => $checksum, 'statements' => migrationStatements($sql)];
        } elseif (!hash_equals($applied[$migration], $checksum)) {
            throw new RuntimeException('Migração já aplicada foi alterada: ' . $file . '. Restaure o arquivo e crie uma nova migração.');
        }
    }
    return $pending;
}

function checkMigrations(PDO $db): void
{
    $pending = pendingMigrations($db);
    if ($pending) {
        throw new RuntimeException('Migrações pendentes: ' . implode(', ', array_keys($pending)) . '.');
    }
}

/** @return array{applied:list<string>, skipped:list<string>} */
function runMigrations(PDO $db, ?callable $log = null): array
{
    if ($db->inTransaction()) {
        throw new RuntimeException('Execute as migrações fora de uma transação.');
    }
    $database = $db->query('SELECT DATABASE()')->fetchColumn();
    if (!is_string($database) || $database === '') {
        throw new RuntimeException('Selecione um banco existente na conexão antes de migrar.');
    }
    $serverVersion = (string) $db->getAttribute(PDO::ATTR_SERVER_VERSION);
    $lockName = 'burger-house:migrate:' . substr(hash('sha256', $database), 0, 40);
    $lock = $db->prepare('SELECT GET_LOCK(?, 15)');
    $lock->execute([$lockName]);
    if ((int) $lock->fetchColumn() !== 1) {
        throw new RuntimeException('Outra migração está em andamento. Tente novamente ao terminar.');
    }

    $result = ['applied' => [], 'skipped' => []];
    try {
        $db->exec(migrationSqlForServer(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                migration VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
                checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP())
             ) ENGINE=InnoDB', $serverVersion,
        ));
        $pending = pendingMigrations($db);
        $result['skipped'] = array_keys(array_diff_key(migrationManifest(), $pending));
        $insert = $db->prepare('INSERT INTO schema_migrations (migration, checksum) VALUES (?, ?)');
        foreach ($pending as $migration => $details) {
            // MySQL DDL commits implicitly. Each file must be safe to resume after partial failure.
            foreach ($details['statements'] as $statement) {
                $db->exec(migrationSqlForServer($statement, $serverVersion));
            }
            $insert->execute([$migration, $details['checksum']]);
            $result['applied'][] = $migration;
            if ($log !== null) {
                $log('Aplicada: ' . $migration);
            }
        }
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
    return $result;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        if (array_slice($argv, 1) === ['--check']) {
            checkMigrations(databaseConnection());
            echo 'Todas as migrações desta versão estão aplicadas e conferidas.', PHP_EOL;
            exit(0);
        }
        if (count($argv) !== 1) {
            throw new InvalidArgumentException('Uso: php bin/migrate.php [--check]');
        }
        $result = runMigrations(databaseConnection(), static fn (string $message) => print($message . PHP_EOL));
        echo count($result['applied']), ' aplicada(s), ', count($result['skipped']), ' já registrada(s).', PHP_EOL;
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Migração falhou: ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}
