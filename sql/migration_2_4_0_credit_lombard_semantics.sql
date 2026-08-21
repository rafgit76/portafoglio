-- Portafoglio v2.4.0 - separazione esplicita tra patrimonio Fineco e credito disponibile.
-- Prima di importare: esportare il database completo da phpMyAdmin.
-- La migrazione aggiunge solo colonne nullable e non modifica gli snapshot esistenti.
-- Alcune installazioni pilota contengono gia fineco_portfolio_total: i controlli
-- INFORMATION_SCHEMA la rendono riutilizzabile senza errore di colonna duplicata.

SET NAMES utf8mb4;

SET @schema_name = DATABASE();

SET @migration_sql = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `pf_patrimonial_snapshots` ADD COLUMN `fineco_portfolio_total` DECIMAL(20,4) NULL AFTER `fineco_total_assets`',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'pf_patrimonial_snapshots' AND COLUMN_NAME = 'fineco_portfolio_total'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `pf_patrimonial_snapshots` ADD COLUMN `reserved_liquidity` DECIMAL(20,4) NULL AFTER `liquidity_total`',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'pf_patrimonial_snapshots' AND COLUMN_NAME = 'reserved_liquidity'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `pf_patrimonial_snapshots` ADD COLUMN `net_assets_adjusted` DECIMAL(20,4) NULL AFTER `credit_available_amount`',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'pf_patrimonial_snapshots' AND COLUMN_NAME = 'net_assets_adjusted'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `pf_patrimonial_snapshots` ADD COLUMN `own_liquidity_adjusted` DECIMAL(20,4) NULL AFTER `net_assets_adjusted`',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'pf_patrimonial_snapshots' AND COLUMN_NAME = 'own_liquidity_adjusted'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
