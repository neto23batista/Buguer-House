<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../app/admin-rate-limit.php';
require_once __DIR__ . '/../app/mysql-session.php';
require_once __DIR__ . '/../bin/migrate.php';
require_once __DIR__ . '/../bin/maintenance.php';

testSame(0, adminAccountFailureDelayMicroseconds(24), 'conta sem pressão adicional não recebe atraso');
testSame(250000, adminAccountFailureDelayMicroseconds(25), '25 falhas ativam atraso de 250 ms');
testSame(500000, adminAccountFailureDelayMicroseconds(30), 'atraso da conta cresce progressivamente');
testSame(1000000, adminAccountFailureDelayMicroseconds(65536), 'atraso da conta fica limitado a um segundo');
testThrows(fn () => runMaintenance(new class extends PDO { public function __construct() {} public function inTransaction(): bool { return true; } }),
    RuntimeException::class, 'manutenção recusa transação aberta');

$dsn = getenv('SECURITY_TEST_DSN');
if ($dsn === false) {
    $dsn = getenv('DB_TEST_DSN');
}
if ($dsn === false && getenv('REQUIRE_DB_TESTS') === '1') {
    $dsn = 'mysql:host=' . databaseSetting('DB_HOST', '127.0.0.1')
        . ';port=' . databaseSetting('DB_PORT', '3306')
        . ';dbname=' . databaseSetting('DB_NAME', '') . ';charset=utf8mb4';
}
if ($dsn === false) {
    if (getenv('REQUIRE_SECURITY_TESTS') === '1') {
        throw new RuntimeException('Configure SECURITY_TEST_DSN para executar as regressões obrigatórias.');
    }
    echo "SKIP: regressões de segurança no banco (configure DB_TEST_DSN ou SECURITY_TEST_DSN local).\n";
    exit;
}
if (!str_starts_with($dsn, 'mysql:')
    || !preg_match('/(?:^mysql:|;)host=(127\.0\.0\.1|localhost|\[::1\]|::1)(?:;|$)/', $dsn)
    || !preg_match('/(?:^mysql:|;)dbname=([a-zA-Z0-9_]+)(?:;|$)/', $dsn, $match)
    || (!str_ends_with($match[1], '_test') && !str_starts_with($match[1], 'bh_test_'))) {
    throw new RuntimeException('Regressões recusadas: use banco MySQL local terminado em _test ou iniciado por bh_test_.');
}
$database = $match[1];
$configuration = databaseConfiguration();
$user = databaseSetting('SECURITY_TEST_USER', databaseSetting('DB_TEST_USER', databaseSetting('DB_USER', 'root')));
$password = databaseSetting('SECURITY_TEST_PASSWORD', databaseSetting('DB_TEST_PASSWORD', databaseSetting('DB_PASSWORD', '')));
ini_set('mysqlnd.net_read_timeout', (string) $configuration['read_timeout']);
$db = new PDO($dsn, $user, $password, $configuration['options']);
if ($db->query('SELECT DATABASE()')->fetchColumn() !== $database) {
    throw new RuntimeException('O banco conectado não corresponde ao banco dedicado da regressão.');
}
if ($configuration['tls']) {
    $status = $db->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch();
    if (!$status || ($status['Value'] ?? '') === '') {
        throw new PDOException('O banco não estabeleceu a conexão TLS exigida.');
    }
}
$db->exec("SET time_zone = '+00:00'");
$db->exec('SET SESSION innodb_lock_wait_timeout = 5');
runMigrations($db);
$marker = bin2hex(random_bytes(6));
$account = 'audit-' . $marker;
$accountBucket = 'account:' . hash('sha256', $account);
$loginKeys = [$accountBucket];
$sessionKeys = [];
$orderQuotaKeys = [];
$orderIds = [];
$serverAddress = $_SERVER['REMOTE_ADDR'] ?? null;
$originSegment = dechex(random_int(1, 65535));
$origin = static function (int $number) use ($originSegment, &$loginKeys): void {
    $_SERVER['REMOTE_ADDR'] = '2001:db8:' . $originSegment . ':' . dechex($number) . '::1';
    $loginKeys[] = adminLoginClientKey();
};
$delays = [];
$throttle = static function (int $microseconds) use ($db, &$delays): void {
    testAssert(!$db->inTransaction(), 'atraso não mantém transação ou locks de linha abertos');
    $delays[] = $microseconds;
};

try {
    for ($client = 1; $client <= 5; $client++) {
        $origin($client);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            checkAdminLogin($db, $account, static fn (): bool => false, $throttle);
        }
    }
    testSame([250000], $delays, 'tentativas distribuídas ativam proteção progressiva da conta');
    $origin(6);
    $verified = false;
    testSame(true, checkAdminLogin($db, $account, static function () use (&$verified): bool {
        $verified = true;
        return true;
    }, $throttle), 'senha correta de nova origem funciona após 25 falhas distribuídas');
    testAssert($verified, 'orçamento global não impede verificar uma senha correta');
    testSame([250000], $delays, 'senha correta não recebe a penalidade de falha da conta');

    $origin(7);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        checkAdminLogin($db, $account, static fn (): bool => false, $throttle);
    }
    $verified = false;
    testSame(null, checkAdminLogin($db, $account, static function () use (&$verified): bool {
        $verified = true;
        return true;
    }, $throttle), 'origem que esgotou cinco falhas permanece bloqueada');
    testAssert(!$verified, 'origem bloqueada não executa verificação de senha');

    $origin(8);
    $db->prepare('UPDATE admin_login_attempts SET attempts=65535 WHERE bucket=?')->execute([$accountBucket]);
    checkAdminLogin($db, $account, static fn (): bool => false, $throttle);
    $query = $db->prepare('SELECT attempts FROM admin_login_attempts WHERE bucket=?');
    $query->execute([$accountBucket]);
    testSame(65535, (int) $query->fetchColumn(), 'contador da conta satura sem ultrapassar SMALLINT');
    $origin(9);
    testThrows(fn () => checkAdminLogin($db, $account, static fn () => throw new RuntimeException('fixture'), $throttle),
        RuntimeException::class, 'falha do verificador é propagada');
    testAssert(!$db->inTransaction(), 'falha do verificador libera transação');

    $insertSession = $db->prepare('INSERT INTO web_sessions(session_id,payload,expires_at) VALUES (?, ?, ?)');
    $db->beginTransaction();
    for ($index = 0; $index < 1001; $index++) {
        $key = hash('sha256', $marker . '-expired-' . $index);
        $sessionKeys[] = $key;
        $insertSession->execute([$key, '', '2000-01-01 00:00:00']);
    }
    $activeSession = hash('sha256', $marker . '-active');
    $sessionKeys[] = $activeSession;
    $insertSession->execute([$activeSession, 'active', '2099-01-01 00:00:00']);
    $db->commit();
    $expiredLogin = 'client:' . hash('sha256', $marker . '-expired');
    $activeLogin = 'client:' . hash('sha256', $marker . '-active');
    $loginKeys = [...$loginKeys, $expiredLogin, $activeLogin];
    $insertLogin = $db->prepare('INSERT INTO admin_login_attempts(bucket,attempts,expires_at) VALUES (?,1,?)');
    $insertLogin->execute([$expiredLogin, '2000-01-01 00:00:00']);
    $insertLogin->execute([$activeLogin, '2099-01-01 00:00:00']);
    $insertQuota = $db->prepare('INSERT INTO order_rate_limits(source_hash,order_count,expires_at) VALUES (?,1,?)');
    foreach (['expired' => '2000-01-01 00:00:00', 'active' => '2099-01-01 00:00:00'] as $kind => $expires) {
        $key = hash('sha256', $marker . '-quota-' . $kind);
        $orderQuotaKeys[$kind] = $key;
        $insertQuota->execute([$key, $expires]);
    }

    $insertOrder = $db->prepare('INSERT INTO orders(public_code,checkout_token,customer_name,customer_phone,notes,total_cents,status,created_at,updated_at) VALUES (?,?,?,?,?,12345,?,?,?)');
    $old = (new DateTimeImmutable('-200 days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    foreach ([
        'old_completed' => ['completed', $old, $old],
        'old_cancelled' => ['cancelled', $old, $old],
        'old_active' => ['preparing', $old, $old],
        'recent_completed' => ['completed', $now, $now],
        'recently_closed' => ['completed', $old, $now],
    ] as $label => [$status, $created, $updated]) {
        $insertOrder->execute([strtoupper(bin2hex(random_bytes(8))), bin2hex(random_bytes(32)), 'Cliente da regressão', '11900000000', 'Observação privada', $status, $created, $updated]);
        $orderIds[$label] = (int) $db->lastInsertId();
    }
    $productId = (int) $db->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare('INSERT INTO order_items(order_id,product_id,product_name,unit_price_cents,quantity) VALUES (?,?,?,12345,1)')
        ->execute([$orderIds['old_completed'], $productId, 'Snapshot preservado']);
    $db->prepare("INSERT INTO order_status_events(order_id,previous_status,next_status,actor,reason) VALUES (?,'ready','completed','audit','Histórico preservado')")
        ->execute([$orderIds['old_completed']]);

    $configuration = ['batch_size' => 100, 'max_batches' => 1, 'max_seconds' => 10, 'pii_retention_days' => 0];
    $result = runMaintenance($db, $configuration);
    testSame(100, $result['counts']['sessions_deleted'], 'uma execução respeita o orçamento de linhas por tabela');
    testAssert($result['limited'], 'orçamento esgotado sinaliza possível trabalho restante');
    testAssert(!$result['anonymization_enabled'], 'retenção zero deixa anonimização desabilitada');
    $configuration['max_batches'] = 20;
    $result = runMaintenance($db, $configuration);
    testSame(901, $result['counts']['sessions_deleted'], 'manutenção drena todo o restante de sessões expiradas');
    $query = $db->prepare('SELECT COUNT(*) FROM web_sessions WHERE session_id=?');
    $query->execute([$activeSession]);
    testSame(1, (int) $query->fetchColumn(), 'sessão ainda válida é preservada');
    $readOrder = $db->prepare('SELECT customer_name,customer_phone,notes,total_cents,status,created_at,updated_at FROM orders WHERE id=?');
    $readOrder->execute([$orderIds['old_completed']]);
    $before = $readOrder->fetch();
    testSame('11900000000', $before['customer_phone'], 'limpeza sem retenção explícita mantém dados de pedidos');
    $query = $db->prepare('SELECT COUNT(*) FROM admin_login_attempts WHERE bucket=?');
    $query->execute([$activeLogin]);
    testSame(1, (int) $query->fetchColumn(), 'limite de login ainda válido é preservado');
    $query->execute([$expiredLogin]);
    testSame(0, (int) $query->fetchColumn(), 'limite de login expirado é removido');
    $query = $db->prepare('SELECT COUNT(*) FROM order_rate_limits WHERE source_hash=?');
    $query->execute([$orderQuotaKeys['active']]);
    testSame(1, (int) $query->fetchColumn(), 'limite de pedido ainda válido é preservado');
    $query->execute([$orderQuotaKeys['expired']]);
    testSame(0, (int) $query->fetchColumn(), 'limite de pedido expirado é removido');

    $configuration['pii_retention_days'] = 90;
    $preview = runMaintenance($db, $configuration, true);
    testSame(2, $preview['counts']['orders_anonymized'], 'dry-run identifica somente pedidos finais antigos');
    $readOrder->execute([$orderIds['old_completed']]);
    testSame($before, $readOrder->fetch(), 'dry-run não modifica dados pessoais');
    $result = runMaintenance($db, $configuration);
    testSame(2, $result['counts']['orders_anonymized'], 'retenção explícita anonimiza concluídos e cancelados antigos');
    $readOrder->execute([$orderIds['old_completed']]);
    $after = $readOrder->fetch();
    testSame(['Cliente anonimizado', '', ''], [$after['customer_name'], $after['customer_phone'], $after['notes']], 'nome, telefone e observações antigos são anonimizados');
    foreach (['total_cents', 'status', 'created_at', 'updated_at'] as $field) {
        testSame($before[$field], $after[$field], 'anonimização preserva ' . $field);
    }
    foreach (['old_active', 'recent_completed', 'recently_closed'] as $label) {
        $readOrder->execute([$orderIds[$label]]);
        testSame('11900000000', $readOrder->fetch()['customer_phone'], 'anonimização preserva pedido ' . $label);
    }
    $query = $db->prepare('SELECT COUNT(*) FROM order_items WHERE order_id=?');
    $query->execute([$orderIds['old_completed']]);
    testSame(1, (int) $query->fetchColumn(), 'anonimização preserva itens do pedido');
    $query = $db->prepare('SELECT reason FROM order_status_events WHERE order_id=?');
    $query->execute([$orderIds['old_completed']]);
    testSame('Histórico preservado', $query->fetchColumn(), 'anonimização preserva histórico de status');
    testSame(0, runMaintenance($db, $configuration)['counts']['orders_anonymized'], 'anonimização pode ser repetida sem recontar pedidos');

    $second = new PDO($dsn, $user, $password, databaseConfiguration()['options']);
    $lockName = 'bh-maintenance:' . substr(hash('sha256', $database), 0, 49);
    $lock = $second->prepare('SELECT GET_LOCK(?, 0)');
    $lock->execute([$lockName]);
    testAssert(runMaintenance($db, $configuration)['skipped'], 'outra manutenção em andamento impede execução simultânea');
    $second->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    $second = null;
    testThrows(fn () => runMaintenance($db, ['batch_size' => 0] + $configuration), InvalidArgumentException::class,
        'configuração inválida de lote é recusada antes de SQL mutável');
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    foreach ($orderIds as $id) {
        $db->prepare('DELETE FROM order_status_events WHERE order_id=?')->execute([$id]);
        $db->prepare('DELETE FROM order_items WHERE order_id=?')->execute([$id]);
        $db->prepare('DELETE FROM orders WHERE id=?')->execute([$id]);
    }
    foreach (array_chunk($sessionKeys, 250) as $keys) {
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $db->prepare('DELETE FROM web_sessions WHERE session_id IN (' . $placeholders . ')')->execute($keys);
    }
    foreach (array_unique($loginKeys) as $key) {
        $db->prepare('DELETE FROM admin_login_attempts WHERE bucket=?')->execute([$key]);
    }
    foreach ($orderQuotaKeys as $key) {
        $db->prepare('DELETE FROM order_rate_limits WHERE source_hash=?')->execute([$key]);
    }
    if ($serverAddress === null) {
        unset($_SERVER['REMOTE_ADDR']);
    } else {
        $_SERVER['REMOTE_ADDR'] = $serverAddress;
    }
}
echo "PASS: regressões de segurança concluídas; fixtures removidas.\n";
