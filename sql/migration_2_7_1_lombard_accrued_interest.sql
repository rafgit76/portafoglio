-- Portafoglio v2.7.1 - rateo nel controllo del rapporto Credit Lombard.
-- Aggiunge soltanto campi descrittivi. Non modifica snapshot o posizioni esistenti.
SET NAMES utf8mb4;

SET @schema_name = DATABASE();

SET @migration_sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `pf_snapshot_positions` ADD COLUMN `accrued_interest` DECIMAL(20,4) NOT NULL DEFAULT 0 AFTER `market_value`',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'pf_snapshot_positions' AND COLUMN_NAME = 'accrued_interest'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `pf_credit_lombard_checks` ADD COLUMN `expected_market_value` DECIMAL(20,4) NULL AFTER `expected_snapshot_date`',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'pf_credit_lombard_checks' AND COLUMN_NAME = 'expected_market_value'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `pf_credit_lombard_checks` ADD COLUMN `expected_accrued_interest` DECIMAL(20,4) NULL AFTER `expected_market_value`',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'pf_credit_lombard_checks' AND COLUMN_NAME = 'expected_accrued_interest'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
