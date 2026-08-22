-- Portafoglio v2.7.0 - controlli opzionali del rapporto Credit Lombard.
-- Archivia soltanto gli esiti di verifica dell'export patrimonio del rapporto.
-- Non modifica snapshot, posizioni, P/L, patrimonio o linee di credito.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS pf_credit_lombard_checks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  credit_line_id BIGINT UNSIGNED NOT NULL,
  check_date DATE NOT NULL,
  source_file_name VARCHAR(255) NOT NULL,
  detected_dossier_identifier VARCHAR(64) NOT NULL,
  dossier_source_label VARCHAR(255) NULL,
  fineco_total_assets DECIMAL(20,4) NOT NULL,
  accounting_balance DECIMAL(20,4) NULL,
  available_balance DECIMAL(20,4) NULL,
  liquidity_total DECIMAL(20,4) NOT NULL,
  portfolio_total DECIMAL(20,4) NOT NULL,
  dossier_total DECIMAL(20,4) NOT NULL,
  internal_difference DECIMAL(20,4) NOT NULL DEFAULT 0,
  expected_snapshot_id BIGINT UNSIGNED NULL,
  expected_snapshot_date DATE NULL,
  expected_portfolio_total DECIMAL(20,4) NULL,
  difference_amount DECIMAL(20,4) NULL,
  check_status VARCHAR(32) NOT NULL,
  parsed_payload_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_lombard_check_credit_line FOREIGN KEY (credit_line_id) REFERENCES pf_credit_lines(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_lombard_check_snapshot FOREIGN KEY (expected_snapshot_id) REFERENCES pf_snapshots(id) ON DELETE SET NULL ON UPDATE CASCADE,
  KEY idx_lombard_check_line_date (credit_line_id, check_date),
  KEY idx_lombard_check_status (check_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
