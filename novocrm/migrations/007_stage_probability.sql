-- Probabilidade (%) exibida no card do kanban. NULL = não exibir.
ALTER TABLE funnel_stages ADD COLUMN probability TINYINT UNSIGNED NULL;
