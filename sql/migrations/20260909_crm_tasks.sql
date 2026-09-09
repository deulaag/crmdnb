-- Tarefas comerciais vinculadas às oportunidades e configuração de lembrete.
CREATE TABLE IF NOT EXISTS crm_tasks (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  orcamento_id INT NOT NULL,
  assigned_to_user_id INT NOT NULL,
  created_by_user_id INT NULL,
  title VARCHAR(180) NOT NULL,
  due_date DATE NOT NULL,
  status ENUM('pending','done','cancelled') NOT NULL DEFAULT 'pending',
  completed_at DATETIME NULL,
  reminder_sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_crm_tasks_user_due (assigned_to_user_id,status,due_date),
  KEY idx_crm_tasks_quote (orcamento_id,status),
  CONSTRAINT fk_crm_tasks_quote FOREIGN KEY (orcamento_id) REFERENCES orcamentos(id) ON DELETE CASCADE,
  CONSTRAINT fk_crm_tasks_assigned FOREIGN KEY (assigned_to_user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_crm_tasks_creator FOREIGN KEY (created_by_user_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_task_settings (
  id TINYINT NOT NULL PRIMARY KEY,
  reminder_enabled TINYINT(1) NOT NULL DEFAULT 1,
  reminder_days_before INT NOT NULL DEFAULT 1,
  reminder_template VARCHAR(1000) NOT NULL DEFAULT 'Lembrete: a tarefa {tarefa} do orçamento {orcamento} para {cliente} vence em {dias} dia(s), em {data}.',
  updated_by_user_id INT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_crm_task_settings_user FOREIGN KEY (updated_by_user_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO crm_task_settings (id) VALUES (1)
ON DUPLICATE KEY UPDATE id=VALUES(id);
