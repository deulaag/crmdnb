-- NOVOCRM v3 (Fase 7) — rastreio de importação, IDs externos e índices de busca.
-- Somente aditivo: colunas NULL e índices. O run.php confere information_schema antes de cada comando.
ALTER TABLE companies ADD COLUMN external_id VARCHAR(120) NULL;
ALTER TABLE companies ADD COLUMN person_document VARCHAR(14) NULL;
ALTER TABLE companies ADD COLUMN merged_ids_json LONGTEXT NULL;
ALTER TABLE companies ADD COLUMN import_batch_id INT UNSIGNED NULL;
ALTER TABLE companies ADD COLUMN import_synced_at DATETIME NULL;
ALTER TABLE contacts ADD COLUMN external_id VARCHAR(120) NULL;
ALTER TABLE contacts ADD COLUMN import_batch_id INT UNSIGNED NULL;
ALTER TABLE contacts ADD COLUMN import_synced_at DATETIME NULL;
ALTER TABLE contacts ADD COLUMN created_by INT UNSIGNED NULL;
ALTER TABLE opportunities ADD COLUMN external_source VARCHAR(50) NULL;
ALTER TABLE opportunities ADD COLUMN external_ref VARCHAR(120) NULL;
ALTER TABLE opportunities ADD COLUMN import_batch_id INT UNSIGNED NULL;
ALTER TABLE opportunities ADD COLUMN import_synced_at DATETIME NULL;
CREATE INDEX idx_companies_cnpj ON companies (cnpj);
CREATE INDEX idx_companies_external ON companies (external_id);
CREATE INDEX idx_contacts_email ON contacts (email);
CREATE INDEX idx_contacts_external ON contacts (external_id);
CREATE INDEX idx_opp_external ON opportunities (external_source, external_ref);
