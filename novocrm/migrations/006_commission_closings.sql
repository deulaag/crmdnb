-- Fechamento mensal de comissões: um snapshot por pessoa e mês.
CREATE TABLE IF NOT EXISTS commission_closings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  reference_month CHAR(7) NOT NULL,
  invoiced_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  commission_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  details_json LONGTEXT NULL,
  status ENUM('fechada','paga') NOT NULL DEFAULT 'fechada',
  closed_by INT UNSIGNED NULL,
  closed_at DATETIME NOT NULL,
  paid_at DATE NULL,
  paid_by INT UNSIGNED NULL,
  UNIQUE KEY uq_closing (user_id, reference_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
