-- ZNP Development v5.2.0
-- Safe, rerunnable upgrade: archive individual Company + Trade relationships.

SET @znp_has_archived_at := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'construction_company_trades'
      AND COLUMN_NAME = 'archived_at'
);

SET @znp_sql := IF(
    @znp_has_archived_at = 0,
    'ALTER TABLE construction_company_trades ADD COLUMN archived_at DATETIME NULL AFTER updated_at, ADD INDEX idx_company_trade_archived (archived_at)',
    'SELECT 1'
);

PREPARE znp_stmt FROM @znp_sql;
EXECUTE znp_stmt;
DEALLOCATE PREPARE znp_stmt;
