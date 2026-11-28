<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../bin/migrate.php';
require_once __DIR__ . '/../app/orders.php';
require_once __DIR__ . '/../app/kitchen.php';
require_once __DIR__ . '/../app/reports.php';
require_once __DIR__ . '/../app/admin-auth.php';
require_once __DIR__ . '/../app/admin-rate-limit.php';

function integrationDatabase(): PDO
{
    $dsn = getenv('DB_TEST_DSN');
    if ($dsn === false) {
        if (getenv('REQUIRE_DB_TESTS') !== '1') {
            throw new RuntimeException('Configure DB_TEST_DSN ou REQUIRE_DB_TESTS=1 para executar a integração.');
        }
        $dsn = 'mysql:host=' . databaseSetting('DB_HOST', '127.0.0.1')
            . ';port=' . databaseSetting('DB_PORT', '3306')
            . ';dbname=' . databaseSetting('DB_NAME', '') . ';charset=utf8mb4';
    }
    if (!str_starts_with($dsn, 'mysql:') || !preg_match('/(?:^mysql:|;)dbname=([a-zA-Z0-9_]+)(?:;|$)/', $dsn, $match)) {
        throw new RuntimeException('DB_TEST_DSN deve ser MySQL e conter dbname explícito.');
    }
    if (!str_ends_with($match[1], '_test') && !str_starts_with($match[1], 'bh_test_')) {
        throw new RuntimeException('Integração recusada: use um banco dedicado com nome terminado em _test ou iniciado por bh_test_.');
    }
    $config = databaseConfiguration();
    ini_set('mysqlnd.net_read_timeout', (string) $config['read_timeout']);
    $db = new PDO($dsn, databaseSetting('DB_TEST_USER', databaseSetting('DB_USER', 'root')),
        databaseSetting('DB_TEST_PASSWORD', databaseSetting('DB_PASSWORD', '')), $config['options']);
    if ($db->query('SELECT DATABASE()')->fetchColumn() !== $match[1]) {
        throw new RuntimeException('O banco conectado não corresponde ao banco de teste solicitado.');
    }
    if ($config['tls']) {
        $status = $db->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch();
        if (!$status || ($status['Value'] ?? '') === '') {
            throw new PDOException('O banco não estabeleceu a conexão TLS exigida.');
        }
    }
    $db->exec("SET time_zone = '+00:00'");
    $db->exec('SET SESSION innodb_lock_wait_timeout=5');
    return $db;
}

function integrationWorker(array $payload): array
{
    $deadline = microtime(true) + 10;
    do {
        clearstatcache(true, $payload['barrier']);
        if (is_file($payload['barrier'])) {
            break;
        }
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('O teste concorrente não liberou a barreira.');
        }
        usleep(10000);
    } while (true);
    $db = integrationDatabase();
    if ($payload['action'] === 'order') {
        $order = placeOrder($db, $payload['token'], $payload['customer'], $payload['quantities'],
            $payload['fingerprint'], $payload['address']);
        return ['id' => $order['id']];
    }
    return ['changed' => changeKitchenOrderStatus($db, $payload['id'], 'received', 'preparing', $payload['actor'])];
}

/** Run two real PHP/MySQL connections together; workers wait until both have been started. */
function integrationRace(array $payload): array
{
    $barrier = tempnam(sys_get_temp_dir(), 'bh-race-');
    if ($barrier === false) {
        throw new RuntimeException('Não foi possível criar a barreira do teste concorrente.');
    }
    unlink($barrier);
    $workers = [];
    try {
        for ($index = 0; $index < 2; $index++) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, 'tests/integration.php', '--worker'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__));
            if (!is_resource($process)) {
                throw new RuntimeException('Não foi possível iniciar um processo de teste concorrente.');
            }
            fwrite($pipes[0], json_encode($payload + ['barrier' => $barrier], JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        touch($barrier);
        $results = [];
        foreach ($workers as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            if ($exitCode !== 0) {
                throw new RuntimeException('Processo concorrente falhou: ' . trim($error));
            }
            $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        }
        $workers = [];
        return $results;
    } finally {
        foreach ($workers as [$process, $pipes]) {
            if (is_resource($process)) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($process);
            }
        }
        if (is_file($barrier)) {
            unlink($barrier);
        }
    }
}

if (($argv[1] ?? '') === '--worker') {
    try {
        echo json_encode(integrationWorker(json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR);
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}

// Buffer assertions until session checks have sent their headers.
ob_start();
$db = null;
$storeBefore = null;
$productId = null;
$phones = [];
$sessionDigest = null;
$loginKeys = [];
$suiteLock = null;
$environmentBefore = [];
$failure = null;
try {
    $db = integrationDatabase();
    $suiteLock = 'bh:integration:' . substr(hash('sha256', (string) $db->query('SELECT DATABASE()')->fetchColumn()), 0, 40);
    $lock = $db->prepare('SELECT GET_LOCK(?, 15)');
    $lock->execute([$suiteLock]);
    if ((int) $lock->fetchColumn() !== 1) {
        throw new RuntimeException('Outra suíte de integração está usando este banco.');
    }
    runMigrations($db);
    $reapplied = runMigrations($db);
    testSame([], $reapplied['applied'], 'migrações podem ser executadas novamente sem reaplicar dados');
    testSame(count(migrationManifest()), count($reapplied['skipped']), 'todas as migrações possuem registro e checksum');
    $indexes = $db->query("SELECT COUNT(DISTINCT INDEX_NAME) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders' AND INDEX_NAME IN ('idx_orders_created_at','idx_orders_status_created')")->fetchColumn();
    testSame(2, (int) $indexes, 'índices dos relatórios estão presentes');

    $storeBefore = $db->query('SELECT * FROM store_settings WHERE id=1')->fetch();
    $reportBounds = reportPeriod([])['bounds'];
    $reportBefore = orderReport($db, $reportBounds)['totals'];
    $schedule = array_fill(1, 7, [['00:00', '24:00']]);
    $address = 'Rua dos Testes, 123';
    $setupStore = $db->prepare('UPDATE store_settings SET pickup_address=?, prep_minutes=25, paused=0, schedule=?, exceptions=? WHERE id=1');
    $setupStore->execute([$address, json_encode($schedule, JSON_THROW_ON_ERROR), '{}']);
    $suffix = bin2hex(random_bytes(8));
    $insert = $db->prepare('INSERT INTO products (slug,name,description,category,price_cents,image,image_alt,sort_order,is_active) VALUES (?,?,?,?,?,?,?,?,1)');
    $insert->execute(['integration-' . $suffix, 'Burger integração ' . $suffix, 'Fixture temporária', 'burgers', 2500, 'house-smash.webp', 'Burger de teste', 32000]);
    $productId = (int) $db->lastInsertId();
    $quantities = [$productId => 2];
    $fingerprint = cartFingerprint(cartSummary($quantities, loadProducts($db)));
    $phones[] = $phone = '119' . random_int(10000000, 99999999);
    $customer = ['name' => 'Cliente de integração', 'phone' => $phone, 'notes' => 'Fixture temporária'];
    $token = bin2hex(random_bytes(32));
    $order = placeOrder($db, $token, $customer, $quantities, $fingerprint, $address);
    testThrows(fn () => placeOrder($db, bin2hex(random_bytes(32)), $customer, $quantities, $fingerprint, $address, null, 999),
        DomainException::class, 'mudança de prazo dentro da transação exige revisão', 'prazo');
    $duplicate = placeOrder($db, $token, $customer, $quantities, $fingerprint, $address);
    testSame($order['id'], $duplicate['id'], 'token repetido devolve o mesmo pedido');
    $quota = $db->prepare('SELECT order_count FROM order_rate_limits WHERE source_hash=?');
    $quota->execute([hash('sha256', "order-phone\0" . $phone)]);
    testSame(1, (int) $quota->fetchColumn(), 'repetir um pedido não consome outra cota');
    testSame(5000, $order['total_cents'], 'total persistido usa preços do servidor');

    $db->exec('UPDATE products SET price_cents=2600 WHERE id=' . $productId);
    testThrows(fn () => placeOrder($db, bin2hex(random_bytes(32)), $customer, $quantities, $fingerprint, $address), DomainException::class,
        'alteração de preço exige nova revisão do checkout', 'carrinho mudou');
    $db->exec('UPDATE products SET price_cents=2500 WHERE id=' . $productId);
    $db->exec('UPDATE store_settings SET paused=1 WHERE id=1');
    testThrows(fn () => placeOrder($db, bin2hex(random_bytes(32)), $customer, $quantities, $fingerprint, $address), DomainException::class,
        'loja pausada recusa pedidos');
    $db->exec("UPDATE store_settings SET paused=0, schedule=JSON_OBJECT() WHERE id=1");
    testThrows(fn () => placeOrder($db, bin2hex(random_bytes(32)), $customer, $quantities, $fingerprint, $address), DomainException::class,
        'loja fora de horário recusa pedidos', 'fechada');
    $setupStore->execute([$address, json_encode($schedule, JSON_THROW_ON_ERROR), '{}']);
    testThrows(fn () => placeOrder($db, bin2hex(random_bytes(32)), $customer, $quantities, $fingerprint, 'Endereço antigo'), DomainException::class,
        'mudança de endereço exige confirmação', 'endereço');
    for ($index = 0; $index < 2; $index++) {
        placeOrder($db, bin2hex(random_bytes(32)), $customer, $quantities, $fingerprint, $address);
    }
    testThrows(fn () => placeOrder($db, bin2hex(random_bytes(32)), $customer, $quantities, $fingerprint, $address), OrderRateLimitException::class,
        'quarto pedido pelo mesmo telefone é bloqueado');
    $phoneQuotaKey = hash('sha256', "order-phone\0" . $phone);
    $expireQuota = $db->prepare('UPDATE order_rate_limits SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE source_hash=?');
    $expireQuota->execute([$phoneQuotaKey]);
    $db->beginTransaction();
    consumeOrderQuota($db, $phone, null);
    $db->commit();
    $quotaDetails = $db->prepare('SELECT order_count, expires_at, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), expires_at) AS seconds_left FROM order_rate_limits WHERE source_hash=?');
    $quotaDetails->execute([$phoneQuotaKey]);
    $renewedQuota = $quotaDetails->fetch();
    testSame(1, (int) $renewedQuota['order_count'], 'janela expirada reinicia a contagem antes de consumir a nova cota');
    testAssert((int) $renewedQuota['seconds_left'] > 0 && (int) $renewedQuota['seconds_left'] <= ORDER_WINDOW_SECONDS,
        'renovar janela expirada define uma nova expiração');
    $shortenQuota = $db->prepare('UPDATE order_rate_limits SET expires_at=TIMESTAMPADD(SECOND, 120, UTC_TIMESTAMP()) WHERE source_hash=?');
    $shortenQuota->execute([$phoneQuotaKey]);
    $quotaDetails->execute([$phoneQuotaKey]);
    $fixedExpiry = $quotaDetails->fetch()['expires_at'];
    $db->beginTransaction();
    consumeOrderQuota($db, $phone, null);
    $db->commit();
    $quotaDetails->execute([$phoneQuotaKey]);
    $activeQuota = $quotaDetails->fetch();
    testSame(2, (int) $activeQuota['order_count'], 'janela ativa acumula a cota consumida');
    testSame($fixedExpiry, $activeQuota['expires_at'], 'consumir cota em janela ativa não prolonga sua expiração');
    $largeCart = [$productId => 41];
    testThrows(fn () => placeOrder($db, bin2hex(random_bytes(32)), $customer, $largeCart,
        cartFingerprint(cartSummary($largeCart, loadProducts($db))), $address), OrderRateLimitException::class,
        'limite de unidades é validado no servidor');

    $phones[] = $racePhone = '119' . random_int(10000000, 99999999);
    $race = integrationRace(['action' => 'order', 'token' => bin2hex(random_bytes(32)),
        'customer' => array_replace($customer, ['phone' => $racePhone]), 'quantities' => $quantities, 'fingerprint' => $fingerprint, 'address' => $address]);
    testSame($race[0]['id'], $race[1]['id'], 'checkouts simultâneos com o mesmo token criam um único pedido');
    $statusRace = integrationRace(['action' => 'status', 'id' => $race[0]['id'], 'actor' => 'integration-' . $suffix]);
    testSame(1, count(array_filter(array_column($statusRace, 'changed'))), 'uma única atualização vence quando o status esperado é o mesmo');
    testSame(1, count(listOrderStatusEvents($db, $race[0]['id'])), 'corrida de status grava um único evento');

    $tracking = orderTrackingDetails($db, $order['public_code']);
    testAssert(canReadOrderTracking($tracking, orderTrackingToken($token)), 'link privado válido permite acompanhar o pedido');
    testAssert(!canReadOrderTracking($tracking, str_repeat('0', 64)), 'link com chave inválida é recusado');
    $db->exec('UPDATE order_tracking SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE order_id=' . $order['id']);
    testAssert(!canReadOrderTracking(orderTrackingDetails($db, $order['public_code']), orderTrackingToken($token)), 'link expirado é recusado');
    testAssert(changeKitchenOrderStatus($db, $order['id'], 'received', 'preparing', 'integration-' . $suffix), 'pedido inicia preparo');
    testAssert(changeKitchenOrderStatus($db, $order['id'], 'preparing', 'ready', 'integration-' . $suffix), 'pedido fica pronto');
    testThrows(fn () => changeKitchenOrderStatus($db, $order['id'], 'ready', 'completed', 'integration'),
        DomainException::class, 'retirada sem pagamento preserva o status pronto', 'forma de pagamento');
    testAssert(changeKitchenOrderStatus($db, $order['id'], 'ready', 'completed', 'integration-' . $suffix, '', '', 'pix'), 'pedido é concluído');
    testSame('pix', orderTrackingDetails($db, $order['public_code'])['payment_method'], 'recibo preserva forma de pagamento');
    testAssert(!changeKitchenOrderStatus($db, $order['id'], 'completed', 'preparing', 'integration-' . $suffix), 'estado final não reabre pedido');
    testSame(2500, listOrderItems($db, $order['id'])[0]['unit_price_cents'], 'itens mantêm o preço original da compra');
    $reportAfter = orderReport($db, $reportBounds)['totals'];
    testSame((int) $reportBefore['completed'] + 1, (int) $reportAfter['completed'], 'relatório conta o pedido concluído');
    testSame((int) $reportBefore['total'] + 5000, (int) $reportAfter['total'], 'relatório soma a compra sem duplicar itens');

    foreach (['APP_ENV', 'SESSION_DRIVER', 'SESSION_TTL_SECONDS', 'ADMIN_USER', 'ADMIN_PASSWORD_HASH', 'OPERATOR_USER', 'OPERATOR_PASSWORD_HASH'] as $key) {
        $environmentBefore[$key] = getenv($key);
    }
    putenv('APP_ENV=test');
    putenv('SESSION_DRIVER=files');
    putenv('SESSION_TTL_SECONDS=43200');
    $ownerName = 'it-owner-' . $suffix;
    $operatorName = 'it-operator-' . $suffix;
    $ownerHash = password_hash('integration-owner-password', PASSWORD_DEFAULT);
    $operatorHash = password_hash('integration-operator-password', PASSWORD_DEFAULT);
    putenv('ADMIN_USER=' . $ownerName);
    putenv('ADMIN_PASSWORD_HASH=' . $ownerHash);
    putenv('OPERATOR_USER=' . $operatorName);
    putenv('OPERATOR_PASSWORD_HASH=' . $operatorHash);
    $_SERVER['REMOTE_ADDR'] = '2001:db8:' . substr($suffix, 0, 4) . ':' . substr($suffix, 4, 4) . '::1';
    $loginKeys = [adminLoginClientKey(), 'account:' . hash('sha256', $ownerName), 'account:' . hash('sha256', $operatorName)];
    testSame(true, checkAdminLogin($db, $ownerName, fn () => password_verify('integration-owner-password', $ownerHash)), 'login valida hash de senha real');
    for ($index = 0; $index < 4; $index++) {
        testSame(false, checkAdminLogin($db, $operatorName, static fn () => false), 'tentativa inválida de login é registrada');
    }
    testSame(null, checkAdminLogin($db, $operatorName, static fn () => false), 'quinta falha bloqueia a origem');
    session_id('bh-it-' . $suffix);
    $auth = beginAdminSession();
    foreach (['owner', 'operator'] as $role) {
        $_SESSION['admin_session'] = ['role' => $role, 'version' => $auth['accounts'][$role]['version'], 'started_at' => time(), 'last_seen' => time()];
        $state = beginAdminSession();
        testAssert($state['loggedIn'] && $state['role'] === $role && $state['user'] === $auth['accounts'][$role]['user'], 'sessão preserva a identidade do perfil ' . $role);
    }
    $_SESSION['admin_session']['started_at'] = time() - ADMIN_SESSION_SECONDS - 1;
    testSame(false, beginAdminSession()['loggedIn'], 'sessão administrativa expira no limite absoluto');
    session_destroy();

    require_once __DIR__ . '/../app/mysql-session.php';
    $sessionId = 'integration-session-' . $suffix;
    $sessionDigest = hash('sha256', $sessionId);
    $handler = new MySqlSessionHandler(static fn () => integrationDatabase(), 43200, 1);
    $handler->open('', '');
    testSame('', $handler->read($sessionId), 'sessão nova começa vazia no MySQL');
    testAssert($handler->write($sessionId, 'cart|a:1:{i:1;i:2;}'), 'sessão grava no MySQL');
    $contender = new MySqlSessionHandler(static fn () => integrationDatabase(), 43200, 1);
    testThrows(static fn () => $contender->read($sessionId), RuntimeException::class,
        'duas conexões não podem alterar o mesmo carrinho simultaneamente');
    $contender->close();
    $handler->close();
    $secondHandler = new MySqlSessionHandler(static fn () => integrationDatabase(), 43200, 1);
    $secondHandler->open('', '');
    testAssert($secondHandler->validateId($sessionId), 'outra conexão reconhece a sessão persistida');
    testSame('cart|a:1:{i:1;i:2;}', $secondHandler->read($sessionId), 'sessão é compartilhada entre conexões');
    $secondHandler->close();
    $expireSession = $db->prepare('UPDATE web_sessions SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE session_id=?');
    $expireSession->execute([$sessionDigest]);
    testSame(false, $secondHandler->validateId($sessionId), 'sessão MySQL expirada não valida');
    testSame('', $secondHandler->read($sessionId), 'sessão expirada não devolve o carrinho anterior');
    $secondHandler->close();
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    foreach ($environmentBefore as $key => $value) {
        putenv($value === false ? $key : $key . '=' . $value);
    }
    if ($db instanceof PDO) {
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($productId !== null) {
                $orderIds = $db->query('SELECT DISTINCT order_id FROM order_items WHERE product_id=' . $productId)->fetchAll(PDO::FETCH_COLUMN);
                if ($orderIds !== []) {
                    $ids = implode(',', array_map('intval', $orderIds));
                    foreach (['payments', 'pickup_tickets', 'order_tracking', 'order_status_events', 'order_items'] as $table) {
                        $db->exec('DELETE FROM ' . $table . ' WHERE order_id IN (' . $ids . ')');
                    }
                    $db->exec('DELETE FROM orders WHERE id IN (' . $ids . ')');
                }
                $db->exec('DELETE FROM products WHERE id=' . $productId);
            }
            if ($phones !== []) {
                $deleteQuota = $db->prepare('DELETE FROM order_rate_limits WHERE source_hash=?');
                foreach ($phones as $fixturePhone) {
                    $deleteQuota->execute([hash('sha256', "order-phone\0" . $fixturePhone)]);
                }
            }
            if ($loginKeys !== []) {
                $deleteLogin = $db->prepare('DELETE FROM admin_login_attempts WHERE bucket=?');
                foreach ($loginKeys as $key) {
                    $deleteLogin->execute([$key]);
                }
            }
            if ($sessionDigest !== null) {
                $deleteSession = $db->prepare('DELETE FROM web_sessions WHERE session_id=?');
                $deleteSession->execute([$sessionDigest]);
            }
            if ($storeBefore !== null) {
                $restore = $db->prepare('UPDATE store_settings SET pickup_address=?,prep_minutes=?,paused=?,schedule=?,exceptions=? WHERE id=1');
                $restore->execute([$storeBefore['pickup_address'], $storeBefore['prep_minutes'], $storeBefore['paused'], $storeBefore['schedule'], $storeBefore['exceptions']]);
            }
        } catch (Throwable $cleanupException) {
            $failure ??= $cleanupException;
            fwrite(STDERR, 'Falha ao limpar fixtures: ' . $cleanupException->getMessage() . PHP_EOL);
        } finally {
            if ($suiteLock !== null) {
                $release = $db->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$suiteLock]);
            }
        }
    }
}
ob_end_flush();
if ($failure !== null) {
    fwrite(STDERR, get_class($failure) . ': ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
echo 'PASS: integração MySQL concluída e fixtures removidas.', PHP_EOL;
