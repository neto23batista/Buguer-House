-- Resolve indexes against the connected database, including installations created before migrations.
SET @bh_report_index = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE orders ADD INDEX idx_orders_created_at (created_at, id)',
        'DO 0')
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_created_at'
);
PREPARE bh_report_index_statement FROM @bh_report_index;
EXECUTE bh_report_index_statement;
DEALLOCATE PREPARE bh_report_index_statement;

SET @bh_report_index = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE orders ADD INDEX idx_orders_status_created (status, created_at, id)',
        'DO 0')
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_status_created'
);
PREPARE bh_report_index_statement FROM @bh_report_index;
EXECUTE bh_report_index_statement;
DEALLOCATE PREPARE bh_report_index_statement;
