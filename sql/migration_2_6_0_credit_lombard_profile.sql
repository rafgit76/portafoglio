-- Portafoglio v2.6.0 - profilo ufficiale del rapporto Credit Lombard.
-- Aggiunge solo metadati anagrafici alla linea di credito. Non modifica snapshot,
-- posizioni, P/L, consolidamento o importi patrimoniali storici.
SET NAMES utf8mb4;

SET @schema_name = DATABASE();

SET @migration_sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `pf_credit_lines` ADD COLUMN `validity_type` ENUM(''REVOCABLE'',''FIXED_TERM'',''OTHER'') NULL AFTER `status`',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'pf_credit_lines' AND COLUMN_NAME = 'validity_type'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `pf_credit_lines` ADD COLUMN `linked_main_account_id` VARCHAR(64) NULL AFTER `validity_type`',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'pf_credit_lines' AND COLUMN_NAME = 'linked_main_account_id'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `pf_credit_lines` ADD COLUMN `pledge_account_id` VARCHAR(64) NULL AFTER `linked_main_account_id`',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'pf_credit_lines' AND COLUMN_NAME = 'pledge_account_id'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `pf_credit_lines` ADD COLUMN `pledge_dossier_id` VARCHAR(64) NULL AFTER `pledge_account_id`',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'pf_credit_lines' AND COLUMN_NAME = 'pledge_dossier_id'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `pf_credit_lines` ADD COLUMN `data_origin` VARCHAR(64) NOT NULL DEFAULT ''manual'' AFTER `pledge_dossier_id`',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'pf_credit_lines' AND COLUMN_NAME = 'data_origin'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `pf_credit_lines` ADD COLUMN `source_document_name` VARCHAR(255) NULL AFTER `data_origin`',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'pf_credit_lines' AND COLUMN_NAME = 'source_document_name'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

-- Registra il rapporto ufficiale documentato dalla lettera Fineco del 21/08/2026.
-- Se una linea Credit Lombard esiste gia, viene completata senza toccare l'utilizzato.
SET @credit_lombard_id = (
    SELECT id FROM pf_credit_lines WHERE credit_type = 'CREDIT_LOMBARD' ORDER BY id ASC LIMIT 1
);
SET @credit_lombard_dossier_id = (
    SELECT id FROM pf_dossiers WHERE code = 'credit_lombard' ORDER BY id ASC LIMIT 1
);

UPDATE pf_credit_lines
SET name = IF(TRIM(name) = '', 'Credit Lombard Fineco', name),
    bank_name = COALESCE(NULLIF(TRIM(bank_name), ''), 'Fineco'),
    agreed_amount = 12000.0000,
    activation_date = '2026-08-21',
    status = 'ACTIVE',
    validity_type = 'REVOCABLE',
    linked_main_account_id = '3274177',
    pledge_account_id = '5243936',
    pledge_dossier_id = '5243936',
    data_origin = 'fineco_acceptance_letter',
    source_document_name = 'lettera-accettazione-140821.pdf',
    dossier_id = COALESCE(dossier_id, @credit_lombard_dossier_id)
WHERE id = @credit_lombard_id;

INSERT INTO pf_credit_lines
    (name, bank_name, credit_type, agreed_amount, used_amount, activation_date, status, validity_type,
     linked_main_account_id, pledge_account_id, pledge_dossier_id, data_origin, source_document_name, dossier_id)
SELECT
    'Credit Lombard Fineco', 'Fineco', 'CREDIT_LOMBARD', 12000.0000, 0.0000, '2026-08-21', 'ACTIVE', 'REVOCABLE',
    '3274177', '5243936', '5243936', 'fineco_acceptance_letter', 'lettera-accettazione-140821.pdf', @credit_lombard_dossier_id
WHERE @credit_lombard_id IS NULL;
