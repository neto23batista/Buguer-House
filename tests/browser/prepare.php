<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bin/migrate.php';

$name = databaseSetting('DB_NAME', '');
if (!str_ends_with($name, '_test') && !str_starts_with($name, 'bh_test_')) {
    throw new RuntimeException('Use um banco dedicado terminado em _test ou iniciado por bh_test_.');
}
$statePath = databaseSetting('BH_BROWSER_STATE', '');
if ($statePath === '') throw new RuntimeException('Estado temporário de E2E ausente.');
$db = databaseConnection();
if ($db->query('SELECT DATABASE()')->fetchColumn() !== $name) throw new RuntimeException('Banco inesperado.');

if (($argv[1] ?? '') === '--cleanup') {
    if (!is_file($statePath)) exit(0);
    $state = json_decode(file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    if ($state['database'] !== $name) throw new RuntimeException('O estado de E2E pertence a outro banco.');
    $query = $db->prepare('UPDATE store_settings SET pickup_address=?,prep_minutes=?,paused=?,schedule=?,exceptions=? WHERE id=1');
    $store = $state['store'];
    $query->execute([$store['pickup_address'],$store['prep_minutes'],$store['paused'],$store['schedule'],$store['exceptions']]);
    $db->prepare('DELETE FROM products WHERE id=?')->execute([$state['productId']]);
    unlink($statePath);
    echo 'Fixtures E2E removidas; loja restaurada.', PHP_EOL;
    exit(0);
}

runMigrations($db);
$store = $db->query('SELECT pickup_address,prep_minutes,paused,schedule,exceptions FROM store_settings WHERE id=1')->fetch();
$db->beginTransaction();
try {
    $insert = $db->prepare("INSERT INTO products(slug,name,description,category,price_cents,image,image_alt,sort_order,is_active)
        VALUES (?,?,'Produto exclusivo dos testes de navegador','burgers',1234,'','',0,1)");
    $insert->execute(['e2e-' . bin2hex(random_bytes(12)), 'Produto E2E']);
    $productId = (int) $db->lastInsertId();
    $schedule = array_fill(1, 7, [['00:00', '24:00']]);
    $query = $db->prepare('UPDATE store_settings SET pickup_address=?,paused=0,schedule=?,exceptions=? WHERE id=1');
    $query->execute(['Balcão exclusivo dos testes E2E', json_encode($schedule), '{}']);
    $state = ['database'=>$name,'store'=>$store,'productId'=>$productId];
    if (file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível registrar as fixtures.');
    }
    $db->commit();
    echo 'Fixtures E2E preparadas.', PHP_EOL;
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    throw $error;
}
