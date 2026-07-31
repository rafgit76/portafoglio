SET NAMES utf8mb4;

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
