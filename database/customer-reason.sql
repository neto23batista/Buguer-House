SET @bh_ddl = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE order_status_events ADD COLUMN customer_reason VARCHAR(100) NOT NULL DEFAULT ''''', 'DO 0')
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_status_events' AND COLUMN_NAME = 'customer_reason');
PREPARE bh_change FROM @bh_ddl;
EXECUTE bh_change;
DEALLOCATE PREPARE bh_change;
