-- Eseguire manualmente solo dopo un backup completo del database.
-- Non modifica gli snapshot, le linee di credito o i tassi gia registrati.
CREATE TABLE IF NOT EXISTS pf_credit_lombard_rates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  valid_from DATE NOT NULL,
  tan DECIMAL(10,6) NOT NULL,
  spread DECIMAL(10,6) NOT NULL,
  overdraft_tan DECIMAL(10,6) NOT NULL,
  credit_limit DECIMAL(20,4) NOT NULL,
  benchmark VARCHAR(120) NOT NULL,
  notes VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lombard_rate_valid_from (valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO pf_credit_lombard_rates (valid_from, tan, spread, overdraft_tan, credit_limit, benchmark, notes)
SELECT '2026-08-07', 0.034000, 0.010000, 0.111500, 12000.0000, 'Euribor 3M 360', 'Condizioni contratto Credit Lombard Fineco'
WHERE NOT EXISTS (SELECT 1 FROM pf_credit_lombard_rates WHERE valid_from = '2026-08-07');
