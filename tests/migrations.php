<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../bin/migrate.php';

$create = <<<'SQL'
CREATE TABLE orders (
    created_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    updated_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    quantity INT NOT NULL DEFAULT 1
)
SQL;
$createForTiDB = <<<'SQL'
CREATE TABLE orders (
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    quantity INT NOT NULL DEFAULT 1
)
SQL;
$alter = '  alter table orders add created_at DATETIME default ( utc_timestamp ( ) )';

testSame($create, migrationSqlForServer($create, '8.4.0'),
    'MySQL preserva os defaults UTC originais ao criar tabela');
testSame($alter, migrationSqlForServer($alter, '8.0.22'),
    'MySQL preserva o SQL original ao alterar tabela');
testSame($createForTiDB, migrationSqlForServer($create, '8.0.11-TiDB-v8.5.3'),
    'TiDB adapta todos os defaults UTC de uma tabela e preserva outros defaults');
testSame('  alter table orders add created_at DATETIME DEFAULT CURRENT_TIMESTAMP',
    migrationSqlForServer($alter, '8.0.11-tidb-v8.5.3'),
    'TiDB adapta ALTER TABLE com variações de espaços e maiúsculas');

$existingDefault = 'CREATE TABLE events (created_at DATETIME DEFAULT CURRENT_TIMESTAMP)';
testSame($existingDefault, migrationSqlForServer($existingDefault, '8.0.11-TiDB-v8.5.3'),
    'TiDB preserva defaults já compatíveis');

foreach ([
    'INSERT INTO logs (message) VALUES (\'DEFAULT (UTC_TIMESTAMP())\')',
    'UPDATE orders SET updated_at = UTC_TIMESTAMP()',
    'CREATE VIEW migration_text AS SELECT \'DEFAULT (UTC_TIMESTAMP())\' AS message',
] as $statement) {
    testSame($statement, migrationSqlForServer($statement, '8.0.11-TiDB-v8.5.3'),
        'TiDB preserva instrução fora de CREATE/ALTER TABLE: ' . strtok($statement, ' '));
}
