<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../bin/migrate.php';
require_once __DIR__ . '/../app/orders.php';
require_once __DIR__ . '/../app/kitchen.php';
require_once __DIR__ . '/../app/reports.php';

function functionalDatabase(): PDO
{
    $dsn = getenv('DB_TEST_DSN');
    if ($dsn === false) {
        $dsn = 'mysql:host=' . databaseSetting('DB_HOST', '127.0.0.1')
            . ';port=' . databaseSetting('DB_PORT', '3306')
            . ';dbname=' . databaseSetting('DB_NAME', '') . ';charset=utf8mb4';
    }
    if (!str_starts_with($dsn, 'mysql:')
        || !preg_match('/(?:^mysql:|;)dbname=([a-zA-Z0-9_]+)(?:;|$)/', $dsn, $match)
        || (!str_ends_with($match[1], '_test') && !str_starts_with($match[1], 'bh_test_'))) {
        throw new RuntimeException('Use um banco dedicado terminado em _test ou iniciado por bh_test_.');
    }
    $config = databaseConfiguration();
    ini_set('mysqlnd.net_read_timeout', (string) $config['read_timeout']);
    $db = new PDO($dsn, databaseSetting('DB_TEST_USER', databaseSetting('DB_USER', 'root')),
        databaseSetting('DB_TEST_PASSWORD', databaseSetting('DB_PASSWORD', '')), $config['options']);
    if ($db->query('SELECT DATABASE()')->fetchColumn() !== $match[1]) {
        throw new RuntimeException('O banco conectado não corresponde ao banco de teste solicitado.');
    }
    if ($config['tls']) {
        $tls = $db->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch();
        if (!$tls || ($tls['Value'] ?? '') === '') {
            throw new RuntimeException('O banco de teste não estabeleceu a conexão TLS exigida.');
        }
    }
    $db->exec("SET time_zone = '+00:00'");
    $db->exec('SET SESSION innodb_lock_wait_timeout = 5');
    return $db;
}

/** Schedule a real second connection between report queries, without changing their results. */
final class FunctionalReportStatement extends PDOStatement
{
    protected function __construct(private object $schedule) {}

    public function execute(?array $params = null): bool
    {
        $result = parent::execute($params);
        if ($this->schedule->afterTotals !== null && str_contains($this->queryString, 'COUNT(*) AS received')) {
            $afterTotals = $this->schedule->afterTotals;
            $this->schedule->afterTotals = null;
            $afterTotals();
        }
        return $result;
    }
}

if (getenv('DB_TEST_DSN') === false && getenv('REQUIRE_DB_TESTS') !== '1') {
    echo 'SKIP: regressões funcionais com banco (configure DB_TEST_DSN ou REQUIRE_DB_TESTS=1).', PHP_EOL;
    exit(0);
}

$db = functionalDatabase();
$suiteLock = 'bh:functional:' . substr(hash('sha256', (string) $db->query('SELECT DATABASE()')->fetchColumn()), 0, 40);
$lock = $db->prepare('SELECT GET_LOCK(?, 15)');
$lock->execute([$suiteLock]);
if ((int) $lock->fetchColumn() !== 1) {
    throw new RuntimeException('Outra suíte funcional está usando este banco.');
}
$storeBefore = null;
$productId = null;
$orderIds = [];
$phone = '119' . random_int(10000000, 99999999);
try {
    runMigrations($db);
    $storeBefore = $db->query('SELECT * FROM store_settings WHERE id=1')->fetch();
    $address = 'Retirada exclusiva dos testes funcionais';
    $db->prepare('UPDATE store_settings SET pickup_address=?, paused=0, schedule=?, exceptions=? WHERE id=1')
        ->execute([$address, json_encode((object) array_fill(1, 7, [['00:00', '24:00']])), '{}']);
    $suffix = bin2hex(random_bytes(8));
    $db->prepare('INSERT INTO products (slug,name,description,category,price_cents,image,image_alt) VALUES (?,?,?,?,?,?,?)')
        ->execute(['functional-' . $suffix, 'Original sem bacon', 'Composição original', 'burgers', 2500, 'house-smash.webp', 'Produto original']);
    $productId = (int) $db->lastInsertId();
    $quantities = [$productId => 1];
    $originalProducts = loadProducts($db);
    $original = $originalProducts[$productId];
    $fingerprint = cartFingerprint(cartSummary($quantities, $originalProducts));
    rememberCart($quantities, $originalProducts);
    $snapshot = $_SESSION['cart_snapshot'];
    testSame('', cartChanges($quantities, $quantities, $originalProducts, $snapshot), 'catálogo inalterado não exige revisão');
    $customer = ['name' => 'Cliente de teste', 'phone' => $phone, 'notes' => ''];
    foreach ([
        'name' => 'Novo com bacon',
        'description' => 'Nova composição com bacon',
        'category' => 'acompanhamentos',
        'image' => 'bacon-melt.webp',
        'image_alt' => 'Outra apresentação do produto',
        'price_cents' => 2900,
    ] as $field => $value) {
        $update = $db->prepare('UPDATE products SET ' . $field . '=? WHERE id=?');
        $update->execute([$value, $productId]);
        $changedProducts = loadProducts($db);
        testAssert(cartChanges($quantities, $quantities, $changedProducts, $snapshot) !== '', 'alterar ' . $field . ' informa revisão no carrinho');
        testThrows(fn () => placeOrder($db, bin2hex(random_bytes(32)), $customer, $quantities, $fingerprint, $address),
            DomainException::class, 'alterar ' . $field . ' bloqueia confirmação antiga', 'Seu carrinho mudou');
        $update->execute([$original[$field], $productId]);
    }
    $db->prepare('UPDATE products SET sort_order=100 WHERE id=?')->execute([$productId]);
    testSame($fingerprint, cartFingerprint(cartSummary($quantities, loadProducts($db))), 'reordenar o cardápio preserva a confirmação');

    $db->prepare('UPDATE products SET name=? WHERE id=?')->execute(['Produto revisado', $productId]);
    $reviewedProducts = loadProducts($db);
    $legacySnapshot = [$productId => ['name' => $original['name'], 'price_cents' => $original['price_cents']]];
    testAssert(cartChanges($quantities, $quantities, $reviewedProducts, $legacySnapshot) !== '', 'snapshot antigo ainda detecta troca de nome');
    $reviewedFingerprint = cartFingerprint(cartSummary($quantities, $reviewedProducts));
    $token = bin2hex(random_bytes(32));
    $order = placeOrder($db, $token, $customer, $quantities, $reviewedFingerprint, $address);
    $orderIds[] = $order['id'];
    testSame('Produto revisado', listOrderItems($db, $order['id'])[0]['product_name'], 'nova confirmação grava o produto revisado');
    $db->prepare('UPDATE products SET description=? WHERE id=?')->execute(['Alteração posterior ao pedido', $productId]);
    testSame($order['id'], placeOrder($db, $token, $customer, $quantities, $reviewedFingerprint, $address)['id'],
        'repetir pedido já salvo continua idempotente após alteração do catálogo');

    // A later ID can represent an older order on a distributed database.
    $insertOrder = $db->prepare('INSERT INTO orders (public_code,checkout_token,customer_name,customer_phone,notes,total_cents,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)');
    $chronologicalIds = [];
    foreach (['2035-01-02 12:00:00', '2035-01-01 12:00:00', '2035-01-02 12:00:00'] as $createdAt) {
        $insertOrder->execute([strtoupper(bin2hex(random_bytes(8))), checkoutTokenDigest(bin2hex(random_bytes(32))),
            'Ordem temporal', $phone, '', 2500, $createdAt, $createdAt]);
        $chronologicalIds[] = $id = (int) $db->lastInsertId();
        $orderIds[] = $id;
        $db->prepare('INSERT INTO order_items (order_id,product_id,product_name,unit_price_cents,quantity) VALUES (?,?,?,?,?)')
            ->execute([$id, $productId, 'Ordem temporal', 2500, 1]);
    }
    $recentIds = array_values(array_filter(array_column(listOrders($db, null, 100), 'id'), fn (int $id): bool => in_array($id, $chronologicalIds, true)));
    testSame([$chronologicalIds[2], $chronologicalIds[0], $chronologicalIds[1]], $recentIds, 'pedidos recentes usam data e desempate por ID');
    $kitchenIds = array_values(array_filter(array_column(listKitchenOrders($db)['received'], 'id'), fn (int $id): bool => in_array($id, $chronologicalIds, true)));
    testSame([$chronologicalIds[1], $chronologicalIds[0], $chronologicalIds[2]], $kitchenIds, 'cozinha mantém ordem cronológica com desempate por ID');

    $bounds = ['2020-01-01 00:00:00', '2030-01-01 00:00:00'];
    $before = orderReport($db, $bounds);
    $other = functionalDatabase();
    $schedule = (object) ['afterTotals' => static function () use ($other, $order): void {
        changeKitchenOrderStatus($other, $order['id'], 'received', 'preparing', 'functional-test');
        changeKitchenOrderStatus($other, $order['id'], 'preparing', 'ready', 'functional-test');
        changeKitchenOrderStatus($other, $order['id'], 'ready', 'completed', 'functional-test', '', '', 'pix');
    }];
    $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [FunctionalReportStatement::class, [$schedule]]);
    $during = orderReport($db, $bounds);
    testSame($before['totals'], $during['totals'], 'totais mantêm o snapshot durante conclusão concorrente');
    testSame($before['products'], $during['products'], 'mais vendidos usa o mesmo snapshot dos totais');
    testAssert(!$db->inTransaction(), 'relatório fecha sua transação');
    testSame('READ-COMMITTED', $db->query('SELECT @@transaction_isolation')->fetchColumn(), 'relatório preserva isolamento padrão da conexão');
    $after = orderReport($db, $bounds);
    testSame((int) $before['totals']['completed'] + 1, (int) $after['totals']['completed'], 'relatório seguinte enxerga a conclusão confirmada');
    testSame((int) $before['totals']['total'] + 2500, (int) $after['totals']['total'], 'relatório seguinte inclui o valor concluído');

    $schedule->afterTotals = static fn () => throw new RuntimeException('Falha simulada entre consultas');
    testThrows(fn () => orderReport($db, $bounds), RuntimeException::class, 'falha entre consultas é propagada', 'Falha simulada');
    testAssert(!$db->inTransaction(), 'falha no relatório encerra sua transação');
    $db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
    $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->beginTransaction();
    orderReport($db, $bounds);
    testAssert($db->inTransaction(), 'relatório não confirma a transação pertencente ao chamador');
    $db->rollBack();
} finally {
    $db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if ($productId !== null) {
        // Also remove orders unexpectedly accepted by a failing stale-checkout regression.
        $fixtureOrders = $db->query('SELECT DISTINCT order_id FROM order_items WHERE product_id=' . $productId)
            ->fetchAll(PDO::FETCH_COLUMN);
        $orderIds = array_unique([...$orderIds, ...array_map('intval', $fixtureOrders)]);
    }
    if ($orderIds !== []) {
        $ids = implode(',', array_map('intval', $orderIds));
        foreach (['payments', 'pickup_tickets', 'order_tracking', 'order_status_events', 'order_items'] as $table) {
            $db->exec('DELETE FROM ' . $table . ' WHERE order_id IN (' . $ids . ')');
        }
        $db->exec('DELETE FROM orders WHERE id IN (' . $ids . ')');
    }
    if ($productId !== null) {
        $db->exec('DELETE FROM products WHERE id=' . $productId);
    }
    $db->prepare('DELETE FROM order_rate_limits WHERE source_hash=?')->execute([hash('sha256', "order-phone\0" . $phone)]);
    if ($storeBefore !== null) {
        $db->prepare('UPDATE store_settings SET pickup_address=?,prep_minutes=?,paused=?,schedule=?,exceptions=? WHERE id=1')
            ->execute([$storeBefore['pickup_address'], $storeBefore['prep_minutes'], $storeBefore['paused'], $storeBefore['schedule'], $storeBefore['exceptions']]);
    }
    $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$suiteLock]);
}
echo 'PASS: regressões funcionais concluídas e fixtures removidas.', PHP_EOL;
