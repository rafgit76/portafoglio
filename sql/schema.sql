SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS pf_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  snapshot_date DATE NOT NULL,
  source_file_name VARCHAR(255) NULL,
  notes VARCHAR(1000) NULL,
  total_market_value DECIMAL(20,4) NULL,
  total_cost_basis DECIMAL(20,4) NULL,
  total_unrealized_pnl DECIMAL(20,4) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_snapshot_date (snapshot_date),
  KEY idx_snapshot_date (snapshot_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pf_assets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  asset_type ENUM('AZIONE','PAC','BTP','ETF') NOT NULL,
  name VARCHAR(190) NOT NULL,
  isin VARCHAR(12) NULL,
  ticker VARCHAR(32) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  maturity_date DATE NULL,
  coupon_rate DECIMAL(8,5) NULL,
  coupon_frequency TINYINT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isin (isin),
  KEY idx_name (name),
  KEY idx_ticker (ticker),
  KEY idx_asset_type (asset_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pf_dossiers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(64) NOT NULL,
  name VARCHAR(190) NOT NULL,
  dossier_type ENUM('ORDINARIO','CREDIT_LOMBARD','ALTRO') NOT NULL DEFAULT 'ALTRO',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  notes VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dossier_code (code),
  KEY idx_dossier_active_sort (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO pf_dossiers (code, name, dossier_type, is_active, sort_order, notes)
VALUES
  ('ordinario', 'Dossier ordinario', 'ORDINARIO', 1, 10, 'Dossier Fineco ordinario.'),
  ('credit_lombard', 'Dossier Credit Lombard', 'CREDIT_LOMBARD', 1, 20, 'Dossier a garanzia.');

CREATE TABLE IF NOT EXISTS pf_snapshot_positions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  snapshot_id BIGINT UNSIGNED NOT NULL,
  dossier_id BIGINT UNSIGNED NOT NULL,
  asset_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(20,6) NOT NULL,
  avg_load_price DECIMAL(20,6) NOT NULL,
  current_price DECIMAL(20,6) NOT NULL,
  cost_basis DECIMAL(20,4) NOT NULL,
  market_value DECIMAL(20,4) NOT NULL,
  unrealized_pnl DECIMAL(20,4) NOT NULL,
  weight_pct DECIMAL(8,5) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  data_origin ENUM('IMPORT_FINECO','MANUALE','CORREZIONE') NOT NULL DEFAULT 'IMPORT_FINECO',
  imported_gain_value DECIMAL(20,4) NULL,
  imported_weight_pct DECIMAL(8,5) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pos_snapshot FOREIGN KEY (snapshot_id) REFERENCES pf_snapshots(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_pos_dossier FOREIGN KEY (dossier_id) REFERENCES pf_dossiers(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_pos_asset FOREIGN KEY (asset_id) REFERENCES pf_assets(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  UNIQUE KEY uq_snapshot_asset_dossier (snapshot_id, asset_id, dossier_id),
  KEY idx_pos_snapshot (snapshot_id),
  KEY idx_pos_dossier (dossier_id),
  KEY idx_pos_asset (asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pf_expected_cashflows (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  asset_id BIGINT UNSIGNED NOT NULL,
  flow_type ENUM('CEDOLA','DIVIDENDO') NOT NULL,
  ex_date DATE NULL,
  pay_date DATE NOT NULL,
  amount_per_unit_gross DECIMAL(20,8) NOT NULL,
  withholding_tax_rate DECIMAL(8,5) NULL,
  amount_per_unit_net DECIMAL(20,8) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_exp_asset FOREIGN KEY (asset_id) REFERENCES pf_assets(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  KEY idx_exp_pay_date (pay_date),
  KEY idx_exp_asset (asset_id),
  KEY idx_exp_type (flow_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pf_actual_cashflows (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  asset_id BIGINT UNSIGNED NOT NULL,
  flow_type ENUM('CEDOLA','DIVIDENDO') NOT NULL,
  pay_date DATE NOT NULL,
  quantity_ref DECIMAL(20,6) NULL,
  gross_amount DECIMAL(20,4) NOT NULL,
  withholding_tax_amount DECIMAL(20,4) NOT NULL DEFAULT 0,
  net_amount DECIMAL(20,4) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  expected_cashflow_id BIGINT UNSIGNED NULL,
  notes VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_act_asset FOREIGN KEY (asset_id) REFERENCES pf_assets(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_act_expected FOREIGN KEY (expected_cashflow_id) REFERENCES pf_expected_cashflows(id) ON DELETE SET NULL ON UPDATE CASCADE,
  KEY idx_act_pay_date (pay_date),
  KEY idx_act_asset (asset_id),
  KEY idx_act_type (flow_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pf_import_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  snapshot_id BIGINT UNSIGNED NULL,
  dossier_id BIGINT UNSIGNED NULL,
  file_name VARCHAR(255) NOT NULL,
  source_type ENUM('EXCEL','CSV') NOT NULL DEFAULT 'EXCEL',
  status ENUM('PENDING','RUNNING','COMPLETED','FAILED') NOT NULL DEFAULT 'PENDING',
  rows_total INT UNSIGNED NOT NULL DEFAULT 0,
  rows_ok INT UNSIGNED NOT NULL DEFAULT 0,
  rows_error INT UNSIGNED NOT NULL DEFAULT 0,
  error_summary TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  CONSTRAINT fk_import_snapshot FOREIGN KEY (snapshot_id) REFERENCES pf_snapshots(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_import_dossier FOREIGN KEY (dossier_id) REFERENCES pf_dossiers(id) ON DELETE SET NULL ON UPDATE CASCADE,
  KEY idx_import_status (status),
  KEY idx_import_dossier (dossier_id),
  KEY idx_import_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pf_patrimonial_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  snapshot_date DATE NOT NULL,
  fineco_total_assets DECIMAL(20,4) NULL,
  liquidity_total DECIMAL(20,4) NULL,
  accounting_balance DECIMAL(20,4) NULL,
  available_balance DECIMAL(20,4) NULL,
  credit_lombard_account DECIMAL(20,4) NULL,
  multicurrency_amount DECIMAL(20,4) NULL,
  other_liquidity DECIMAL(20,4) NULL,
  credit_agreed_amount DECIMAL(20,4) NULL,
  credit_used_amount DECIMAL(20,4) NULL,
  credit_available_amount DECIMAL(20,4) NULL,
  imported_values_json LONGTEXT NULL,
  manual_overrides_json LONGTEXT NULL,
  source_file_name VARCHAR(255) NULL,
  notes VARCHAR(2000) NULL,
  data_origin ENUM('MANUALE','IMPORT_FINECO','CALCOLATO') NOT NULL DEFAULT 'MANUALE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_patrimonial_snapshot_date (snapshot_date),
  KEY idx_patrimonial_snapshot_date (snapshot_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pf_patrimonial_dossier_values (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patrimonial_snapshot_id BIGINT UNSIGNED NOT NULL,
  dossier_id BIGINT UNSIGNED NULL,
  source_label VARCHAR(255) NOT NULL,
  amount DECIMAL(20,4) NOT NULL,
  category_breakdown_json LONGTEXT NULL,
  data_origin ENUM('MANUALE','IMPORT_FINECO','CALCOLATO') NOT NULL DEFAULT 'IMPORT_FINECO',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_patrimonial_dossier_snapshot FOREIGN KEY (patrimonial_snapshot_id) REFERENCES pf_patrimonial_snapshots(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_patrimonial_dossier FOREIGN KEY (dossier_id) REFERENCES pf_dossiers(id) ON DELETE SET NULL ON UPDATE CASCADE,
  UNIQUE KEY uq_patrimonial_dossier_value (patrimonial_snapshot_id, source_label),
  KEY idx_patrimonial_dossier_snapshot (patrimonial_snapshot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pf_patrimonial_import_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patrimonial_snapshot_id BIGINT UNSIGNED NOT NULL,
  source_file_name VARCHAR(255) NOT NULL,
  imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  parsed_payload_json LONGTEXT NOT NULL,
  CONSTRAINT fk_patrimonial_import_snapshot FOREIGN KEY (patrimonial_snapshot_id) REFERENCES pf_patrimonial_snapshots(id) ON DELETE CASCADE ON UPDATE CASCADE,
  KEY idx_patrimonial_import_snapshot (patrimonial_snapshot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pf_credit_lines (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  bank_name VARCHAR(190) NULL,
  credit_type ENUM('CREDIT_LOMBARD','MINIFIDO','FIDO_ORDINARIO','ALTRO') NOT NULL DEFAULT 'ALTRO',
  agreed_amount DECIMAL(20,4) NOT NULL DEFAULT 0,
  used_amount DECIMAL(20,4) NOT NULL DEFAULT 0,
  annual_rate DECIMAL(10,6) NULL,
  rate_formula VARCHAR(500) NULL,
  activation_date DATE NULL,
  status ENUM('ACTIVE','PAUSED','CLOSED') NOT NULL DEFAULT 'ACTIVE',
  dossier_id BIGINT UNSIGNED NULL,
  notes VARCHAR(2000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_credit_dossier FOREIGN KEY (dossier_id) REFERENCES pf_dossiers(id) ON DELETE SET NULL ON UPDATE CASCADE,
  KEY idx_credit_status (status),
  KEY idx_credit_dossier (dossier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pf_credit_line_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  credit_line_id BIGINT UNSIGNED NOT NULL,
  snapshot_date DATE NOT NULL,
  agreed_amount DECIMAL(20,4) NOT NULL DEFAULT 0,
  used_amount DECIMAL(20,4) NOT NULL DEFAULT 0,
  data_origin ENUM('MANUALE','IMPORT_FINECO','CALCOLATO') NOT NULL DEFAULT 'MANUALE',
  notes VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_credit_snapshot_line FOREIGN KEY (credit_line_id) REFERENCES pf_credit_lines(id) ON DELETE CASCADE ON UPDATE CASCADE,
  UNIQUE KEY uq_credit_line_snapshot_date (credit_line_id, snapshot_date),
  KEY idx_credit_snapshot_date (snapshot_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pf_internal_transfers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  asset_id BIGINT UNSIGNED NOT NULL,
  source_snapshot_id BIGINT UNSIGNED NOT NULL,
  target_snapshot_id BIGINT UNSIGNED NOT NULL,
  source_dossier_id BIGINT UNSIGNED NOT NULL,
  target_dossier_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(20,6) NOT NULL,
  source_cost_basis DECIMAL(20,4) NULL,
  target_cost_basis DECIMAL(20,4) NULL,
  confidence ENUM('HIGH','MEDIUM','LOW') NOT NULL DEFAULT 'MEDIUM',
  status ENUM('PENDING','CONFIRMED','REJECTED') NOT NULL DEFAULT 'PENDING',
  detected_reason VARCHAR(1000) NOT NULL,
  notes VARCHAR(1000) NULL,
  execution_date DATE NULL,
  settlement_date DATE NULL,
  effective_snapshot_date DATE NULL,
  operation_code VARCHAR(64) NULL,
  source_type VARCHAR(32) NOT NULL DEFAULT 'AUTO',
  source_document_name VARCHAR(255) NULL,
  gross_accrual DECIMAL(20,4) NULL,
  withholding_tax DECIMAL(20,4) NULL,
  total_amount DECIMAL(20,4) NULL,
  confirmed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_transfer_asset FOREIGN KEY (asset_id) REFERENCES pf_assets(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_transfer_source_snapshot FOREIGN KEY (source_snapshot_id) REFERENCES pf_snapshots(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_transfer_target_snapshot FOREIGN KEY (target_snapshot_id) REFERENCES pf_snapshots(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_transfer_source_dossier FOREIGN KEY (source_dossier_id) REFERENCES pf_dossiers(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_transfer_target_dossier FOREIGN KEY (target_dossier_id) REFERENCES pf_dossiers(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  UNIQUE KEY uq_transfer_target_asset_dossier (target_snapshot_id, asset_id, target_dossier_id),
  KEY idx_transfer_status (status),
  KEY idx_transfer_asset (asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pf_snapshot_revisions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  snapshot_id BIGINT UNSIGNED NOT NULL,
  revision_type VARCHAR(64) NOT NULL,
  before_payload MEDIUMTEXT NOT NULL,
  after_payload MEDIUMTEXT NULL,
  details_json MEDIUMTEXT NULL,
  applied_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_revision_snapshot FOREIGN KEY (snapshot_id) REFERENCES pf_snapshots(id) ON DELETE CASCADE ON UPDATE CASCADE,
  KEY idx_revision_snapshot (snapshot_id),
  KEY idx_revision_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pf_earnings_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  asset_id BIGINT UNSIGNED NOT NULL,
  fiscal_period VARCHAR(64) NOT NULL,
  report_date DATE NOT NULL,
  release_timing ENUM('BMO','AMC','DURING','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
  date_confidence ENUM('CONFIRMED','ESTIMATED') NOT NULL DEFAULT 'ESTIMATED',
  source_url VARCHAR(1000) NULL,
  notes VARCHAR(2000) NULL,
  report_markdown MEDIUMTEXT NULL,
  ai_model VARCHAR(100) NULL,
  ai_thinking_level VARCHAR(20) NULL,
  ai_prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  ai_output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  ai_thinking_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  ai_total_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  ai_estimated_cost_usd DECIMAL(12,6) NULL,
  ai_generated_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_earnings_asset FOREIGN KEY (asset_id) REFERENCES pf_assets(id) ON DELETE CASCADE ON UPDATE CASCADE,
  UNIQUE KEY uq_earnings_asset_period_date (asset_id, fiscal_period, report_date),
  KEY idx_earnings_report_date (report_date),
  KEY idx_earnings_asset (asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
