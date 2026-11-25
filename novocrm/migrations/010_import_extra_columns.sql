-- NOVOCRM v3 — campos extras da planilha de contatos (primeiro nome, linhas de interesse, dados de origem). Somente aditivo.
ALTER TABLE contacts ADD COLUMN first_name VARCHAR(100) NULL;
ALTER TABLE companies ADD COLUMN interest_lines VARCHAR(500) NULL;
ALTER TABLE companies ADD COLUMN source_quote_count INT UNSIGNED NULL;
ALTER TABLE companies ADD COLUMN source_last_quote_at DATE NULL;
ALTER TABLE companies ADD COLUMN source_origin VARCHAR(190) NULL;
ALTER TABLE companies ADD COLUMN import_note VARCHAR(500) NULL;
