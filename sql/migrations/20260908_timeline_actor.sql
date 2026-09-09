-- Atribui cada movimentação do lead ao usuário que executou a ação.
ALTER TABLE lead_timeline
  ADD COLUMN actor_user_id INT NULL AFTER actor,
  ADD KEY idx_lead_timeline_actor_date (actor_user_id, created_at),
  ADD CONSTRAINT fk_lead_timeline_actor FOREIGN KEY (actor_user_id) REFERENCES usuarios(id) ON DELETE SET NULL;
