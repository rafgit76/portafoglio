-- Portafoglio v2.5.0 - evidenze contabili e correzioni confermate dei giri interni.
-- Solo aggiunte di colonne: non modifica, non cancella e non ricalcola snapshot esistenti.
SET NAMES utf8mb4;

SET @schema_name = DATABASE();

SET @migration_sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `pf_internal_transfers` ADD COLUMN `execution_date` DATE NULL AFTER `notes`',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'pf_internal_transfers' AND COLUMN_NAME = 'execution_date'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `pf_internal_transfers` ADD COLUMN `settlement_date` DATE NULL AFTER `execution_date`',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'pf_internal_transfers' AND COLUMN_NAME = 'settlement_date'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `pf_internal_transfers` ADD COLUMN `effective_snapshot_date` DATE NULL AFTER `settlement_date`',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'pf_internal_transfers' AND COLUMN_NAME = 'effective_snapshot_date'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `pf_internal_transfers` ADD COLUMN `operation_code` VARCHAR(64) NULL AFTER `effective_snapshot_date`',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'pf_internal_transfers' AND COLUMN_NAME = 'operation_code'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `pf_internal_transfers` ADD COLUMN `source_type` VARCHAR(32) NOT NULL DEFAULT ''AUTO'' AFTER `operation_code`',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'pf_internal_transfers' AND COLUMN_NAME = 'source_type'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `pf_internal_transfers` ADD COLUMN `source_document_name` VARCHAR(255) NULL AFTER `source_type`',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'pf_internal_transfers' AND COLUMN_NAME = 'source_document_name'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `pf_internal_transfers` ADD COLUMN `gross_accrual` DECIMAL(20,4) NULL AFTER `source_document_name`',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'pf_internal_transfers' AND COLUMN_NAME = 'gross_accrual'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `pf_internal_transfers` ADD COLUMN `withholding_tax` DECIMAL(20,4) NULL AFTER `gross_accrual`',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'pf_internal_transfers' AND COLUMN_NAME = 'withholding_tax'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `pf_internal_transfers` ADD COLUMN `total_amount` DECIMAL(20,4) NULL AFTER `withholding_tax`',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'pf_internal_transfers' AND COLUMN_NAME = 'total_amount'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
