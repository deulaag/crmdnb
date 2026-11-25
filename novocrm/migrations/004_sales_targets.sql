-- Metas de faturamento por mês e por ano, por vendedor ou da equipe (user_id NULL = equipe toda).
-- UNIQUE com NULL não impede duplicidade no MySQL: o upsert é feito em código (nc_target_save).
CREATE TABLE IF NOT EXISTS sales_targets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  year SMALLINT UNSIGNED NOT NULL,
  month TINYINT UNSIGNED NULL,
  amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_target (user_id, year, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
