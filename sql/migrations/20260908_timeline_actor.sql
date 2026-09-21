-- Atribui cada movimentação do lead ao usuário que executou a ação.
-- Idempotente para bancos onde parte da estrutura já foi aplicada manualmente.
DROP PROCEDURE IF EXISTS dnb_timeline_actor_update;
DELIMITER $$
CREATE PROCEDURE dnb_timeline_actor_update()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lead_timeline' AND COLUMN_NAME='actor_user_id'
  ) THEN
    ALTER TABLE lead_timeline ADD COLUMN actor_user_id INT NULL AFTER actor;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lead_timeline' AND INDEX_NAME='idx_lead_timeline_actor_date'
  ) THEN
    ALTER TABLE lead_timeline ADD KEY idx_lead_timeline_actor_date (actor_user_id,created_at);
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='lead_timeline' AND CONSTRAINT_NAME='fk_lead_timeline_actor'
  ) THEN
    ALTER TABLE lead_timeline ADD CONSTRAINT fk_lead_timeline_actor
      FOREIGN KEY (actor_user_id) REFERENCES usuarios(id) ON DELETE SET NULL;
  END IF;
END$$
DELIMITER ;
CALL dnb_timeline_actor_update();
DROP PROCEDURE dnb_timeline_actor_update;
